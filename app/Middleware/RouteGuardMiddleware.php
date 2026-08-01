<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * Pfadbasierte Zugriffskontrolle:
 * - Öffentliche Pfade: Login und statische Fehlerseiten
 * - Patientenpfade: erfordern eine durch das Personal gestartete Patientensitzung
 * - Adminpfade: erfordern die Rolle "admin"
 * - Alle übrigen Pfade: erfordern einen angemeldeten Benutzer
 */
final class RouteGuardMiddleware
{
    private const PUBLIC_PATHS = ['/login'];

    public function __invoke(Request $request, callable $next): Response
    {
        $path = rtrim($request->path(), '/') ?: '/';

        if ($path === '/' || in_array($path, self::PUBLIC_PATHS, true)) {
            return $next($request);
        }

        // Kioskpfade: kein Personal-Login nötig – die Geräteauthentifizierung
        // (Geräte-ID + Token) wird im KioskController serverseitig erzwungen.
        if ($path === '/kiosk' || str_starts_with($path, '/kiosk/')) {
            return $next($request);
        }

        if (str_starts_with($path, '/patient')) {
            if (!isset($_SESSION['patient_session']) || !is_array($_SESSION['patient_session'])) {
                return Response::redirect('/login');
            }

            return $next($request);
        }

        $user = $_SESSION['auth_user'] ?? null;
        if (!is_array($user)) {
            return Response::redirect('/login');
        }

        if (str_starts_with($path, '/admin') && ($user['role'] ?? '') !== 'admin') {
            return new Response('Zugriff verweigert', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return $next($request);
    }
}
