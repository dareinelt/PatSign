<?php

declare(strict_types=1);

namespace App\Core;

final class SessionManager
{
    public function __construct(private readonly Config $config) {}

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name('PATSIGNSESSID');
        session_set_cookie_params([
            'lifetime' => $this->config->get('app.security.session_lifetime', 120) * 60,
            'path' => '/',
            'secure' => (bool) $this->config->get('app.security.session_secure', true),
            'httponly' => (bool) $this->config->get('app.security.session_http_only', true),
            'samesite' => (string) $this->config->get('app.security.session_same_site', 'Strict'),
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', (string) ((int) $this->config->get('app.security.session_secure', true)));
        session_start();

        if (!isset($_SESSION['created_at'])) {
            $_SESSION['created_at'] = time();
            session_regenerate_id(true);
        }
    }
}
