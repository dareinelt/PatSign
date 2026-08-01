<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Security\CsrfTokenManager;

final class CsrfMiddleware
{
    public function __construct(private readonly CsrfTokenManager $csrf) {}

    public function __invoke(Request $request, callable $next): Response
    {
        if ($request->method() === 'POST' && !$this->isExempt($request) && !$this->csrf->validate((string) $request->input('_csrf'))) {
            return Response::json(['error' => 'CSRF-Token ungültig'], 419);
        }

        return $next($request);
    }

    /**
     * Kiosk-Endpunkte (außer Registrierung) werden über gerätegebundene Tokens
     * im HTTP-Header authentifiziert und sind dadurch nicht CSRF-anfällig.
     */
    private function isExempt(Request $request): bool
    {
        $path = rtrim($request->path(), '/') ?: '/';

        return str_starts_with($path, '/kiosk/') && $path !== '/kiosk/register';
    }
}
