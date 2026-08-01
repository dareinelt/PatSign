<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ClearingCaseRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $sql = 'INSERT INTO clearing_cases (case_uuid, document_id, status, error_code, ai_confidence, detected_values)
                VALUES (:case_uuid, :document_id, :status, :error_code, :ai_confidence, :detected_values)';
        $this->pdo->prepare($sql)->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $sql = 'SELECT c.*, d.document_id AS document_uuid, d.original_path, d.status AS document_status,
                       d.document_type, d.case_number, d.first_name, d.last_name, d.birth_date,
                       d.analysis_json, d.created_at AS document_created_at,
                       u.username AS editor_username
                FROM clearing_cases c
                INNER JOIN documents d ON d.id = c.document_id
                LEFT JOIN users u ON u.id = c.editor_user_id
                WHERE c.id = :id LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function findOpenByDocumentId(int $documentId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM clearing_cases WHERE document_id = :id AND status IN ('open','in_progress') ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['id' => $documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<int,array<string,mixed>> */
    public function listOpen(int $limit = 200): array
    {
        $sql = "SELECT c.id, c.case_uuid, c.status, c.error_code, c.ai_confidence, c.created_at,
                       c.corrected_values, c.detected_values,
                       d.id AS document_pk, d.original_path, d.document_type, d.case_number,
                       d.first_name, d.last_name, d.birth_date,
                       r.label AS error_label
                FROM clearing_cases c
                INNER JOIN documents d ON d.id = c.document_id
                LEFT JOIN clearing_error_reasons r ON r.code = c.error_code
                WHERE c.status IN ('open','in_progress')
                ORDER BY c.created_at ASC
                LIMIT :limit";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function updateStatus(int $id, string $status, ?int $editorUserId = null): void
    {
        $sql = "UPDATE clearing_cases
                SET status = :status,
                    editor_user_id = COALESCE(:editor_user_id, editor_user_id),
                    processing_started_at = IF(:status2 = 'in_progress' AND processing_started_at IS NULL, NOW(), processing_started_at),
                    completed_at = IF(:status3 IN ('assigned','completed'), COALESCE(completed_at, NOW()), completed_at)
                WHERE id = :id";
        $this->pdo->prepare($sql)->execute([
            'status' => $status,
            'status2' => $status,
            'status3' => $status,
            'editor_user_id' => $editorUserId,
            'id' => $id,
        ]);
    }

    public function updateCorrectedValues(int $id, string $correctedJson, ?int $editorUserId): void
    {
        $sql = "UPDATE clearing_cases
                SET corrected_values = :corrected, editor_user_id = COALESCE(:editor, editor_user_id),
                    status = IF(status = 'open', 'in_progress', status),
                    processing_started_at = COALESCE(processing_started_at, NOW())
                WHERE id = :id";
        $this->pdo->prepare($sql)->execute(['corrected' => $correctedJson, 'editor' => $editorUserId, 'id' => $id]);
    }

    public function updateDetectedValues(int $id, string $detectedJson, ?float $confidence, string $errorCode): void
    {
        $sql = 'UPDATE clearing_cases SET detected_values = :detected, ai_confidence = :confidence, error_code = :error_code WHERE id = :id';
        $this->pdo->prepare($sql)->execute([
            'detected' => $detectedJson,
            'confidence' => $confidence,
            'error_code' => $errorCode,
            'id' => $id,
        ]);
    }

    public function assignFolder(int $id, int $folderId): void
    {
        $this->pdo->prepare('UPDATE clearing_cases SET assigned_patient_folder_id = :folder WHERE id = :id')
            ->execute(['folder' => $folderId, 'id' => $id]);
    }

    public function countOpen(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM clearing_cases WHERE status IN ('open','in_progress')")->fetchColumn();
    }

    /** Kennzahlen für Dashboard-Widget und Statistiken. @return array<string,mixed> */
    public function dashboardStats(): array
    {
        $open = "status IN ('open','in_progress')";
        $row = $this->pdo->query(
            "SELECT
                SUM({$open}) AS open_count,
                SUM(DATE(created_at) = CURDATE()) AS new_today,
                SUM({$open} AND created_at < NOW() - INTERVAL 24 HOUR) AS older_24h,
                SUM({$open} AND created_at < NOW() - INTERVAL 7 DAY) AS older_7d,
                MIN(CASE WHEN {$open} THEN ai_confidence END) AS lowest_confidence,
                AVG(CASE WHEN completed_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, created_at, completed_at) END) AS avg_clearing_minutes
             FROM clearing_cases"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'open_count' => (int) ($row['open_count'] ?? 0),
            'new_today' => (int) ($row['new_today'] ?? 0),
            'older_24h' => (int) ($row['older_24h'] ?? 0),
            'older_7d' => (int) ($row['older_7d'] ?? 0),
            'lowest_confidence' => $row['lowest_confidence'] !== null ? (float) $row['lowest_confidence'] : null,
            'avg_clearing_minutes' => $row['avg_clearing_minutes'] !== null ? (int) round((float) $row['avg_clearing_minutes']) : null,
        ];
    }

    /** Häufigste Fehlergründe. @return array<int,array<string,mixed>> */
    public function topErrorReasons(int $limit = 5): array
    {
        $sql = 'SELECT c.error_code, COALESCE(r.label, c.error_code) AS label, COUNT(*) AS total
                FROM clearing_cases c
                LEFT JOIN clearing_error_reasons r ON r.code = c.error_code
                GROUP BY c.error_code, r.label
                ORDER BY total DESC
                LIMIT :limit';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function countHistoryEvents(string $eventType): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM clearing_case_history WHERE event_type = :event');
        $stmt->execute(['event' => $eventType]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Revisionssichere Historie eines Clearing-Falls.
     *
     * @param array<string,mixed>|null $old
     * @param array<string,mixed>|null $new
     */
    public function addHistory(int $clearingCaseId, string $eventType, ?array $old, ?array $new, ?int $userId): void
    {
        $sql = 'INSERT INTO clearing_case_history (clearing_case_id, event_type, old_values, new_values, user_id)
                VALUES (:case_id, :event_type, :old_values, :new_values, :user_id)';
        $this->pdo->prepare($sql)->execute([
            'case_id' => $clearingCaseId,
            'event_type' => $eventType,
            'old_values' => $old !== null ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
            'new_values' => $new !== null ? json_encode($new, JSON_UNESCAPED_UNICODE) : null,
            'user_id' => $userId,
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function history(int $clearingCaseId): array
    {
        $sql = 'SELECT h.*, u.username
                FROM clearing_case_history h
                LEFT JOIN users u ON u.id = h.user_id
                WHERE h.clearing_case_id = :id
                ORDER BY h.created_at DESC, h.id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $clearingCaseId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
