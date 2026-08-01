<?php

declare(strict_types=1);

return [
    'env' => $_ENV['APP_ENV'] ?? 'production',
    'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL),
    'url' => $_ENV['APP_URL'] ?? 'https://localhost',
    'name' => $_ENV['APP_NAME'] ?? 'PatSign',
    'timezone' => $_ENV['APP_TIMEZONE'] ?? 'Europe/Berlin',
    'max_upload_bytes' => (int) ($_ENV['MAX_UPLOAD_BYTES'] ?? 15_728_640),
    'allowed_upload_mime' => explode(',', $_ENV['ALLOWED_UPLOAD_MIME'] ?? 'application/pdf'),
    'import_watch_path' => $_ENV['IMPORT_WATCH_PATH'] ?? __DIR__ . '/../storage/imports',
    'network_share_path' => $_ENV['NETWORK_SHARE_PATH'] ?? __DIR__ . '/../storage/network-share',
    'security' => [
        'csp' => "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; frame-ancestors 'none'; base-uri 'self'",
        'session_secure' => filter_var($_ENV['SESSION_SECURE'] ?? true, FILTER_VALIDATE_BOOL),
        'session_http_only' => filter_var($_ENV['SESSION_HTTP_ONLY'] ?? true, FILTER_VALIDATE_BOOL),
        'session_same_site' => $_ENV['SESSION_SAME_SITE'] ?? 'Strict',
        'session_lifetime' => (int) ($_ENV['SESSION_LIFETIME'] ?? 120),
    ],
];
