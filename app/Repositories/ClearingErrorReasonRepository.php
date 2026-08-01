<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ClearingErrorReasonRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<int,array<string,mixed>> */
    public function all(bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM clearing_error_reasons' . ($onlyActive ? ' WHERE is_active = 1' : '') . ' ORDER BY code';

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,string> code => label */
    public function labels(): array
    {
        $rows = $this->pdo->query('SELECT code, label FROM clearing_error_reasons')->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        return array_map('strval', $rows);
    }

    public function create(string $code, string $label): void
    {
        $this->pdo->prepare('INSERT INTO clearing_error_reasons (code, label) VALUES (:code, :label)')
            ->execute(['code' => $code, 'label' => $label]);
    }

    public function update(int $id, string $label, bool $isActive): void
    {
        $this->pdo->prepare('UPDATE clearing_error_reasons SET label = :label, is_active = :active WHERE id = :id')
            ->execute(['label' => $label, 'active' => $isActive ? 1 : 0, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM clearing_error_reasons WHERE id = :id')->execute(['id' => $id]);
    }
}
