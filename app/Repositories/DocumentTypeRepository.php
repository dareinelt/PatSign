<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class DocumentTypeRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM document_types ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function create(string $name): int
    {
        $this->pdo->prepare('INSERT INTO document_types (name) VALUES (:name)')->execute(['name' => $name]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name): void
    {
        $this->pdo->prepare('UPDATE document_types SET name = :name WHERE id = :id')->execute(['name' => $name, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM document_types WHERE id = :id')->execute(['id' => $id]);
    }
}
