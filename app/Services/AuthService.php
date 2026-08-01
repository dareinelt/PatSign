<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\UserRepository;
use App\Security\PasswordHasher;

final class AuthService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher
    ) {}

    public function attempt(string $username, string $password): bool
    {
        $user = $this->users->findByUsername($username);
        if ($user === null || !$this->hasher->verify($password, (string) $user['password_hash'])) {
            return false;
        }

        $_SESSION['auth_user'] = [
            'id' => (int) $user['id'],
            'username' => (string) $user['username'],
            'role' => (string) $user['role_name'],
        ];

        session_regenerate_id(true);
        return true;
    }

    public function logout(): void
    {
        unset($_SESSION['auth_user']);
        session_regenerate_id(true);
    }

    public function user(): ?array
    {
        $user = $_SESSION['auth_user'] ?? null;
        return is_array($user) ? $user : null;
    }
}
