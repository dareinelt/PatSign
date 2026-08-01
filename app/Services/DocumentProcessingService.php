<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\NotificationRepository;

/**
 * Führt die KI-Analyse eines importierten Dokuments aus (typischerweise im
 * Hintergrundprozess), aktualisiert den Datensatz und erzeugt eine
 * Benachrichtigung über das Ergebnis.
 */
final class DocumentProcessingService
{
    public function __construct(
        private readonly DocumentAnalysisService $analysis,
        private readonly DocumentRepository $documents,
        private readonly NotificationRepository $notifications,
        private readonly AuditLogRepository $auditLogs
    ) {}

    public function process(int $documentId): void
    {
        $document = $this->documents->findById($documentId);
        if ($document === null) {
            return;
        }

        $path = (string) $document['original_path'];
        $fileName = basename($path);

        try {
            $this->documents->updateStatus($documentId, 'analyzing');
            $start = microtime(true);
            $result = $this->analysis->analyze($path);
            $durationMs = (int) round((microtime(true) - $start) * 1000);

            $caseNumber = isset($result['case_number']) && is_string($result['case_number']) && $result['case_number'] !== ''
                ? $result['case_number']
                : null;
            $firstName = isset($result['first_name']) && is_string($result['first_name']) ? $result['first_name'] : null;
            $lastName = isset($result['last_name']) && is_string($result['last_name']) ? $result['last_name'] : null;
            $birthDate = isset($result['birth_date']) && is_string($result['birth_date']) && $result['birth_date'] !== ''
                ? $result['birth_date']
                : null;
            $documentType = isset($result['document_type']) && is_string($result['document_type']) && $result['document_type'] !== ''
                ? $result['document_type']
                : 'Unbekannt';

            $this->documents->updateAnalysis($documentId, [
                'document_type' => $documentType,
                'case_number' => $caseNumber,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'birth_date' => $birthDate,
                'analysis_json' => json_encode($result, JSON_UNESCAPED_UNICODE),
                'analysis_model' => $_ENV['ANALYSIS_MODEL'] ?? 'gemma-4-e4b',
                'analysis_duration_ms' => $durationMs,
                'patient_key' => hash('sha256', implode('|', [(string) $lastName, (string) $firstName, (string) $birthDate, (string) $caseNumber])),
                'status' => 'analyzed',
            ]);
            $this->auditLog('document_analyzed', ['file' => $fileName], $documentId);

            $patient = trim(($firstName ?? '') . ' ' . ($lastName ?? ''));
            $details = array_filter([
                $documentType !== 'Unbekannt' ? $documentType : null,
                $patient !== '' ? $patient : null,
                $caseNumber !== null ? 'Fall ' . $caseNumber : null,
            ]);
            $this->notify(
                'success',
                'Analyse abgeschlossen: ' . $fileName,
                $details !== [] ? implode(' · ', $details) : 'Das Dokument wurde erfolgreich analysiert.',
                $documentId
            );
        } catch (\Throwable $e) {
            $this->documents->updateStatus($documentId, 'error');
            $this->auditLog('document_analysis_failed', ['file' => $fileName, 'error' => $e->getMessage()], $documentId);
            $this->notify(
                'error',
                'Analyse fehlgeschlagen: ' . $fileName,
                $e->getMessage(),
                $documentId
            );
        }
    }

    private function notify(string $type, string $title, string $message, int $documentId): void
    {
        try {
            $this->notifications->create($type, $title, $message, $documentId);
        } catch (\Throwable) {
            // Benachrichtigungen dürfen die Verarbeitung nicht verhindern.
        }
    }

    /** @param array<string,mixed> $context */
    private function auditLog(string $event, array $context, int $documentId): void
    {
        try {
            $this->auditLogs->log($event, $context, null, $documentId);
        } catch (\Throwable) {
            // Audit-Logging darf die Verarbeitung nicht verhindern.
        }
    }
}
