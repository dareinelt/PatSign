<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\FilenameNormalizer;

final class SignatureService
{
    public function __construct(private readonly FilenameNormalizer $normalizer) {}

    /** @param array<string,string> $document */
    public function buildFinalFilename(array $document): string
    {
        $parts = [
            (string) ($document['case_number'] ?? ''),
            $this->normalizer->normalize((string) (($document['last_name'] ?? '') . ($document['first_name'] ?? ''))),
            preg_replace('/[^0-9]/', '', (string) ($document['birth_date'] ?? '')) ?: '',
            $this->normalizer->normalize((string) ($document['document_type'] ?? 'Unbekannt')),
        ];

        return implode('_', $parts) . '.pdf';
    }

    /** @param array<string,string|bool> $payload */
    public function completionPageMetadata(array $payload): array
    {
        return [
            'document_id' => (string) $payload['document_id'],
            'original_document_reference' => (string) $payload['original_document_reference'],
            'signed_at' => date('c'),
            'patient_name' => (string) $payload['patient_name'],
            'case_number' => (string) $payload['case_number'],
            'document_type' => (string) $payload['document_type'],
            'email_consent' => (bool) ($payload['email_consent'] ?? false),
            'email' => (string) ($payload['email'] ?? ''),
            'clinic' => (string) ($payload['clinic'] ?? ''),
            'operator' => (string) ($payload['operator'] ?? ''),
            'signature_data' => (string) ($payload['signature_data'] ?? ''),
        ];
    }
}
