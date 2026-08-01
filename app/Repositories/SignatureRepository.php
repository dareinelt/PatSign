<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SignatureRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $sql = 'INSERT INTO signatures (document_id, completion_page_path, signed_pdf_path, consent_email, email_address, signed_at, signature_data, operator_name, clinic_name)
                VALUES (:document_id, :completion_page_path, :signed_pdf_path, :consent_email, :email_address, :signed_at, :signature_data, :operator_name, :clinic_name)';
        $this->pdo->prepare($sql)->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function latestForDocument(int $documentId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM signatures WHERE document_id = :document_id ORDER BY signed_at DESC, id DESC LIMIT 1');
        $stmt->execute(['document_id' => $documentId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }
}
