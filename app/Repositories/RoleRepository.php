<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class RoleRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return $this->pdo->query('SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count FROM roles r ORDER BY r.id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function create(string $name): int
    {
        $this->pdo->prepare('INSERT INTO roles (name) VALUES (:name)')->execute(['name' => $name]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name): void
    {
        $this->pdo->prepare('UPDATE roles SET name = :name WHERE id = :id')->execute(['name' => $name, 'id' => $id]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE role_id = :id');
        $stmt->execute(['id' => $id]);
        if ((int) $stmt->fetchColumn() > 0) {
            return false;
        }
        $this->pdo->prepare('DELETE FROM roles WHERE id = :id')->execute(['id' => $id]);
        return true;
    }
}
