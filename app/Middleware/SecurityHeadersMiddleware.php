<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

final class SecurityHeadersMiddleware
{
    public function __construct(private readonly Config $config) {}

    public function __invoke(Request $request, callable $next): Response
    {
        $response = $next($request);

        // PDF-Streaming für den Patientenmodus wird same-origin in einen Viewer eingebettet.
        $frameOptions = $request->path() === '/patient/document' ? 'SAMEORIGIN' : 'DENY';
        $csp = (string) $this->config->get('app.security.csp');
        if ($frameOptions === 'SAMEORIGIN') {
            $csp = str_replace("frame-ancestors 'none'", "frame-ancestors 'self'", $csp);
        }

        return $response
            ->withHeader('X-Frame-Options', $frameOptions)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->withHeader('Content-Security-Policy', $csp);
    }
}
