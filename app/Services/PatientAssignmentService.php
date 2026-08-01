<?php

declare(strict_types=1);

namespace App\Services;

final class PatientAssignmentService
{
    /** @param array<string,mixed> $documentData */
    public function patientKey(array $documentData): string
    {
        return implode('|', [
            (string) ($documentData['case_number'] ?? ''),
            mb_strtolower((string) ($documentData['last_name'] ?? '')),
            mb_strtolower((string) ($documentData['first_name'] ?? '')),
            (string) ($documentData['birth_date'] ?? ''),
        ]);
    }

    /** @param array<int,array<string,mixed>> $documents */
    public function groupByPatient(array $documents): array
    {
        $groups = [];
        foreach ($documents as $document) {
            $groups[$this->patientKey($document)][] = $document;
        }

        return $groups;
    }
}
