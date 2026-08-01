<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class DocumentRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function create(array $data): int
    {
        $sql = 'INSERT INTO documents (document_id, original_path, document_type, case_number, first_name, last_name, birth_date, analysis_json, prompt_version_vision, prompt_version_analysis, analysis_model, analysis_duration_ms, patient_key, status) VALUES (:document_id, :original_path, :document_type, :case_number, :first_name, :last_name, :birth_date, :analysis_json, :prompt_version_vision, :prompt_version_analysis, :analysis_model, :analysis_duration_ms, :patient_key, :status)';
        $this->pdo->prepare($sql)->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM documents WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }
}
