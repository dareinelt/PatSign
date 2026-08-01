<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class AnalysisRunRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $sql = 'INSERT INTO document_analysis_runs (document_id, run_mode, success, result_json, extracted_text, error_message, analysis_model, duration_ms, triggered_by)
                VALUES (:document_id, :run_mode, :success, :result_json, :extracted_text, :error_message, :analysis_model, :duration_ms, :triggered_by)';
        $this->pdo->prepare($sql)->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<int,array<string,mixed>> */
    public function forDocument(int $documentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.run_mode, r.success, r.result_json, r.error_message, r.analysis_model, r.duration_ms, r.created_at, u.username
             FROM document_analysis_runs r
             LEFT JOIN users u ON u.id = r.triggered_by
             WHERE r.document_id = :id
             ORDER BY r.created_at DESC, r.id DESC'
        );
        $stmt->execute(['id' => $documentId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function countForDocument(int $documentId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM document_analysis_runs WHERE document_id = :id');
        $stmt->execute(['id' => $documentId]);

        return (int) $stmt->fetchColumn();
    }

    /** Letzter erfolgreich extrahierter Vision-Text (für "nur Analyse erneut"). */
    public function latestExtractedText(int $documentId): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT extracted_text FROM document_analysis_runs
             WHERE document_id = :id AND extracted_text IS NOT NULL AND extracted_text <> ''
             ORDER BY created_at DESC, id DESC LIMIT 1"
        );
        $stmt->execute(['id' => $documentId]);
        $text = $stmt->fetchColumn();

        return is_string($text) && $text !== '' ? $text : null;
    }
}
