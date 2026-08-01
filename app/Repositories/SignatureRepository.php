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
}
