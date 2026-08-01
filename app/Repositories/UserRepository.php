<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare('SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = :username LIMIT 1');
        $stmt->execute(['username' => $username]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return $this->pdo->query('SELECT u.id, u.username, u.is_active, u.created_at, u.role_id, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id ORDER BY u.username')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function create(string $username, string $passwordHash, int $roleId, bool $isActive = true): int
    {
        $this->pdo->prepare('INSERT INTO users (username, password_hash, role_id, is_active) VALUES (:username, :hash, :role_id, :active)')
            ->execute(['username' => $username, 'hash' => $passwordHash, 'role_id' => $roleId, 'active' => (int) $isActive]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $username, int $roleId, bool $isActive, ?string $passwordHash = null): void
    {
        if ($passwordHash !== null) {
            $this->pdo->prepare('UPDATE users SET username = :username, role_id = :role_id, is_active = :active, password_hash = :hash WHERE id = :id')
                ->execute(['username' => $username, 'role_id' => $roleId, 'active' => (int) $isActive, 'hash' => $passwordHash, 'id' => $id]);
            return;
        }

        $this->pdo->prepare('UPDATE users SET username = :username, role_id = :role_id, is_active = :active WHERE id = :id')
            ->execute(['username' => $username, 'role_id' => $roleId, 'active' => (int) $isActive, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
    }
}
