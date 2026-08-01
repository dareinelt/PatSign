<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class FormResponseRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function findByTemplate(int $templateId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM form_responses WHERE template_id = :id LIMIT 1');
        $stmt->execute(['id' => $templateId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** Legt eine Antwort an (oder liefert die bestehende) – für Autosave/Wiederaufnahme. */
    public function findOrCreate(int $templateId, int $documentId, ?string $caseNumber): array
    {
        $existing = $this->findByTemplate($templateId);
        if ($existing !== null) {
            return $existing;
        }

        $sql = 'INSERT INTO form_responses (response_uuid, template_id, document_id, case_number)
                VALUES (:uuid, :template_id, :document_id, :case_number)';
        $this->pdo->prepare($sql)->execute([
            'uuid' => self::uuid(),
            'template_id' => $templateId,
            'document_id' => $documentId,
            'case_number' => $caseNumber,
        ]);

        return $this->findByTemplate($templateId) ?? [];
    }

    /** Autosave: Wert eines Feldes anlegen oder aktualisieren. */
    public function saveValue(int $responseId, int $fieldId, ?string $value, bool $isValid): void
    {
        $sql = 'INSERT INTO form_response_values (response_id, field_id, value, is_valid)
                VALUES (:response_id, :field_id, :value, :is_valid)
                ON DUPLICATE KEY UPDATE value = VALUES(value), is_valid = VALUES(is_valid)';
        $this->pdo->prepare($sql)->execute([
            'response_id' => $responseId,
            'field_id' => $fieldId,
            'value' => $value,
            'is_valid' => $isValid ? 1 : 0,
        ]);
    }

    /** @return array<int,string|null> field_id => value */
    public function values(int $responseId): array
    {
        $stmt = $this->pdo->prepare('SELECT field_id, value FROM form_response_values WHERE response_id = :id');
        $stmt->execute(['id' => $responseId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int) $row['field_id']] = $row['value'] !== null ? (string) $row['value'] : null;
        }

        return $out;
    }

    public function markCompleted(int $responseId): void
    {
        $this->pdo->prepare("UPDATE form_responses SET status = 'completed', completed_at = NOW() WHERE id = :id AND status <> 'signed'")
            ->execute(['id' => $responseId]);
    }

    public function reopen(int $responseId): void
    {
        $this->pdo->prepare("UPDATE form_responses SET status = 'in_progress', completed_at = NULL WHERE id = :id AND status <> 'signed'")
            ->execute(['id' => $responseId]);
    }

    /** Nach der Unterschrift: Antwort einfrieren und ausgefüllte Version registrieren. */
    public function markSigned(int $responseId, string $filledDocumentId, string $filledPdfPath): void
    {
        $this->pdo->prepare("UPDATE form_responses SET status = 'signed', signed_at = NOW(), filled_document_id = :fdid, filled_pdf_path = :fpath WHERE id = :id")
            ->execute(['id' => $responseId, 'fdid' => $filledDocumentId, 'fpath' => $filledPdfPath]);
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
