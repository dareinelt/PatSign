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

    /** @return array<string,int> */
    public function countsByStatus(): array
    {
        $rows = $this->pdo->query('SELECT status, COUNT(*) FROM documents GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        return array_map('intval', $rows);
    }

    /**
     * Patienten mit offenen (nicht signierten/versendeten) Dokumenten, gruppiert nach Fallnummer.
     *
     * @return array<int,array<string,mixed>>
     */
    public function waitingPatients(int $limit = 25): array
    {
        $sql = "SELECT case_number, MAX(last_name) AS last_name, MAX(first_name) AS first_name,
                       COUNT(*) AS document_count,
                       SUM(status IN ('signed','sent','archived')) AS done_count,
                       SUM(status = 'error') AS error_count,
                       SUM(status IN ('ready','analyzed')) AS ready_count,
                       MAX(updated_at) AS updated_at
                FROM documents
                WHERE case_number IS NOT NULL
                GROUP BY case_number
                HAVING done_count < document_count
                ORDER BY updated_at DESC
                LIMIT :limit";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Heute unterschriebene Dokumente inkl. Signaturzeitpunkt.
     *
     * @return array<int,array<string,mixed>>
     */
    public function signedToday(): array
    {
        $sql = "SELECT d.id, d.document_type, d.case_number, d.first_name, d.last_name, d.status,
                       MAX(s.signed_at) AS signed_at, MAX(s.operator_name) AS operator_name
                FROM documents d
                INNER JOIN signatures s ON s.document_id = d.id
                WHERE DATE(s.signed_at) = CURDATE()
                GROUP BY d.id, d.document_type, d.case_number, d.first_name, d.last_name, d.status
                ORDER BY signed_at DESC";

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int,array<string,mixed>> */
    public function search(string $term, int $limit = 25): array
    {
        $like = '%' . $term . '%';
        $sql = 'SELECT id, document_id, document_type, case_number, first_name, last_name, status, created_at
                FROM documents
                WHERE case_number LIKE :c OR first_name LIKE :f OR last_name LIKE :l
                ORDER BY updated_at DESC
                LIMIT :limit';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('c', $like);
        $stmt->bindValue('f', $like);
        $stmt->bindValue('l', $like);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int,array<string,mixed>> */
    public function findByCaseNumber(string $caseNumber): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM documents WHERE case_number = :case_number ORDER BY created_at');
        $stmt->execute(['case_number' => $caseNumber]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array<string,mixed> $data */
    public function updateAnalysis(int $id, array $data): void
    {
        $sql = 'UPDATE documents SET document_type = :document_type, case_number = :case_number,
                       first_name = :first_name, last_name = :last_name, birth_date = :birth_date,
                       analysis_json = :analysis_json, analysis_model = :analysis_model,
                       analysis_duration_ms = :analysis_duration_ms, patient_key = :patient_key,
                       status = :status
                WHERE id = :id';
        $this->pdo->prepare($sql)->execute($data + ['id' => $id]);
    }

    public function updateStatus(int $id, string $status): void
    {
        $this->pdo->prepare('UPDATE documents SET status = :status WHERE id = :id')->execute(['status' => $status, 'id' => $id]);
    }
}
