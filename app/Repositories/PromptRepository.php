<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class PromptRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function findActiveByType(string $type): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM prompts WHERE type = :type AND is_active = 1 ORDER BY version DESC LIMIT 1');
        $stmt->execute(['type' => $type]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function createVersion(string $type, string $content, string $createdBy): int
    {
        $this->pdo->prepare('INSERT INTO prompts(type, version, content, is_active, created_by) VALUES(:type, (SELECT COALESCE(MAX(version),0)+1 FROM prompts p2 WHERE p2.type=:type2), :content, 0, :created_by)')
            ->execute(['type' => $type, 'type2' => $type, 'content' => $content, 'created_by' => $createdBy]);

        return (int) $this->pdo->lastInsertId();
    }

    public function activateVersion(int $id): void
    {
        $stmt = $this->pdo->prepare('SELECT type FROM prompts WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $type = (string) ($stmt->fetchColumn() ?: '');

        $this->pdo->beginTransaction();
        $this->pdo->prepare('UPDATE prompts SET is_active = 0 WHERE type = :type')->execute(['type' => $type]);
        $this->pdo->prepare('UPDATE prompts SET is_active = 1 WHERE id = :id')->execute(['id' => $id]);
        $this->pdo->commit();
    }
}
