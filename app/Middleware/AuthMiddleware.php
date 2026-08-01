<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

final class AuthMiddleware
{
    /** @param array<int,string> $allowedRoles */
    public function __construct(private readonly array $allowedRoles = []) {}

    public function __invoke(Request $request, callable $next): Response
    {
        $user = $_SESSION['auth_user'] ?? null;
        if (!is_array($user)) {
            return Response::redirect('/login');
        }

        if ($this->allowedRoles !== [] && !in_array($user['role'] ?? '', $this->allowedRoles, true)) {
            return Response::json(['error' => 'Zugriff verweigert'], 403);
        }

        return $next($request);
    }
}
