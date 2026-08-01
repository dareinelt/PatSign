<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class FormFieldTypeRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM form_field_types ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<string> Namen der aktiven Feldtypen */
    public function activeNames(): array
    {
        $rows = $this->pdo->query('SELECT name FROM form_field_types WHERE is_active = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) ?: [];

        return array_map('strval', $rows);
    }
}
