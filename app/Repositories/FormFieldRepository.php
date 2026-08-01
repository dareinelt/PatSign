<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class FormFieldRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $sql = 'INSERT INTO form_fields (field_uuid, template_id, name, type, label, page, x, y, width, height, required, options_json, validation_json, prefill_key, prefill_locked, sort_order)
                VALUES (:field_uuid, :template_id, :name, :type, :label, :page, :x, :y, :width, :height, :required, :options_json, :validation_json, :prefill_key, :prefill_locked, :sort_order)';
        $this->pdo->prepare($sql)->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<int,array<string,mixed>> Felder einer Vorlage, sortiert nach Seite und Reihenfolge */
    public function findByTemplate(int $templateId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM form_fields WHERE template_id = :id ORDER BY page, sort_order, id');
        $stmt->execute(['id' => $templateId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
