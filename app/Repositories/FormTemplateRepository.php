<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class FormTemplateRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $sql = 'INSERT INTO form_templates (template_uuid, document_id, version, source, status, page_count, field_count, required_count, analysis_model, error_message)
                VALUES (:template_uuid, :document_id, :version, :source, :status, :page_count, :field_count, :required_count, :analysis_model, :error_message)';
        $this->pdo->prepare($sql)->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM form_templates WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** Aktuellste einsatzbereite Vorlage eines Dokuments. */
    public function findLatestReadyByDocument(int $documentId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM form_templates WHERE document_id = :id AND status = 'ready' ORDER BY version DESC LIMIT 1");
        $stmt->execute(['id' => $documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function nextVersion(int $documentId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(version), 0) + 1 FROM form_templates WHERE document_id = :id');
        $stmt->execute(['id' => $documentId]);

        return (int) $stmt->fetchColumn();
    }

    public function updateStatus(int $id, string $status, ?string $errorMessage = null): void
    {
        $this->pdo->prepare('UPDATE form_templates SET status = :status, error_message = :error WHERE id = :id')
            ->execute(['status' => $status, 'error' => $errorMessage, 'id' => $id]);
    }

    public function updateMeta(int $id, string $source, ?string $analysisModel): void
    {
        $this->pdo->prepare('UPDATE form_templates SET source = :source, analysis_model = :model WHERE id = :id')
            ->execute(['source' => $source, 'model' => $analysisModel, 'id' => $id]);
    }

    public function updateCounts(int $id, int $pageCount, int $fieldCount, int $requiredCount): void
    {
        $this->pdo->prepare('UPDATE form_templates SET page_count = :p, field_count = :f, required_count = :r WHERE id = :id')
            ->execute(['p' => $pageCount, 'f' => $fieldCount, 'r' => $requiredCount, 'id' => $id]);
    }
}
