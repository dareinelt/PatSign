<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class PatientFolderRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $sql = 'INSERT INTO patient_folders (folder_uuid, case_number, first_name, last_name, birth_date, is_temporary, created_by)
                VALUES (:folder_uuid, :case_number, :first_name, :last_name, :birth_date, :is_temporary, :created_by)';
        $this->pdo->prepare($sql)->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM patient_folders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function findByCaseNumber(string $caseNumber): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM patient_folders WHERE case_number = :cn LIMIT 1');
        $stmt->execute(['cn' => $caseNumber]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function updateCaseNumber(int $id, string $caseNumber, bool $stillTemporary = false): void
    {
        $this->pdo->prepare('UPDATE patient_folders SET case_number = :cn, is_temporary = :temp WHERE id = :id')
            ->execute(['cn' => $caseNumber, 'temp' => $stillTemporary ? 1 : 0, 'id' => $id]);
    }

    /**
     * Live-Suche über Patientenmappen und bereits zugeordnete Dokumente.
     *
     * @return array<int,array<string,mixed>>
     */
    public function search(string $term, int $limit = 15): array
    {
        $like = '%' . $term . '%';
        $sql = "SELECT case_number, last_name, first_name, birth_date, is_temporary, folder_id, document_count FROM (
                    SELECT pf.case_number, pf.last_name, pf.first_name, pf.birth_date, pf.is_temporary,
                           pf.id AS folder_id,
                           (SELECT COUNT(*) FROM documents dx WHERE dx.patient_folder_id = pf.id) AS document_count,
                           pf.updated_at AS sort_at
                    FROM patient_folders pf
                    WHERE pf.case_number LIKE :c1 OR pf.last_name LIKE :l1 OR pf.first_name LIKE :f1
                          OR DATE_FORMAT(pf.birth_date, '%d.%m.%Y') LIKE :b1
                    UNION ALL
                    SELECT d.case_number, MAX(d.last_name), MAX(d.first_name), MAX(d.birth_date), 0,
                           NULL, COUNT(*), MAX(d.updated_at)
                    FROM documents d
                    WHERE d.case_number IS NOT NULL
                          AND d.patient_folder_id IS NULL
                          AND (d.case_number LIKE :c2 OR d.last_name LIKE :l2 OR d.first_name LIKE :f2
                               OR DATE_FORMAT(d.birth_date, '%d.%m.%Y') LIKE :b2)
                    GROUP BY d.case_number
                ) matches
                GROUP BY case_number, last_name, first_name, birth_date, is_temporary, folder_id, document_count
                ORDER BY MAX(sort_at) DESC
                LIMIT :limit";
        $stmt = $this->pdo->prepare($sql);
        foreach (['c1' => $like, 'l1' => $like, 'f1' => $like, 'b1' => $like, 'c2' => $like, 'l2' => $like, 'f2' => $like, 'b2' => $like] as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Nächste freie temporäre Fallnummer (Format T#######, passt in CHAR(8)). */
    public function nextTemporaryCaseNumber(): string
    {
        $stmt = $this->pdo->query("SELECT MAX(CAST(SUBSTRING(case_number, 2) AS UNSIGNED)) FROM patient_folders WHERE case_number LIKE 'T%'");
        $max = (int) $stmt->fetchColumn();

        return 'T' . str_pad((string) ($max + 1), 7, '0', STR_PAD_LEFT);
    }
}
