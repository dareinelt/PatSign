<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AnalysisRunRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\NotificationRepository;
use App\Services\Forms\FormAnalysisService;

/**
 * Führt die KI-Analyse eines importierten Dokuments aus (typischerweise im
 * Hintergrundprozess), aktualisiert den Datensatz und erzeugt eine
 * Benachrichtigung über das Ergebnis. Dokumente, die nicht eindeutig
 * zugeordnet werden können, werden automatisch in den Clearing-Bereich
 * überführt und gehen nicht verloren.
 */
final class DocumentProcessingService
{
    public function __construct(
        private readonly DocumentAnalysisService $analysis,
        private readonly DocumentRepository $documents,
        private readonly NotificationRepository $notifications,
        private readonly AuditLogRepository $auditLogs,
        private readonly ClearingService $clearing,
        private readonly AnalysisRunRepository $analysisRuns,
        private readonly FormAnalysisService $formAnalysis,
        private readonly SettingsService $settings
    ) {}

    public function process(int $documentId): void
    {
        $document = $this->documents->findById($documentId);
        if ($document === null) {
            return;
        }

        // Notfall-Übernahme: bereits ins Clearing verschobene Dokumente
        // werden nicht erneut analysiert.
        if ((string) $document['status'] === 'clearing') {
            return;
        }

        $path = (string) $document['original_path'];
        $fileName = basename($path);
        $model = $_ENV['ANALYSIS_MODEL'] ?? 'gemma-4-e4b';

        $this->documents->updateStatus($documentId, 'analyzing');
        $start = microtime(true);

        // Vision- und Analyse-Schritt getrennt, um den Fehlergrund
        // (OCR_OR_VISION_FAILED / JSON_INVALID / ANALYSIS_FAILED) zu unterscheiden.
        $extractedText = null;
        try {
            $extractedText = $this->analysis->extractText($path);
            $result = $this->analysis->analyzeText($extractedText);
        } catch (\Throwable $e) {
            $errorCode = $extractedText === null
                ? 'OCR_OR_VISION_FAILED'
                : (str_contains($e->getMessage(), 'JSON') ? 'JSON_INVALID' : 'ANALYSIS_FAILED');
            $this->recordRun($documentId, false, null, $extractedText, $e->getMessage(), $model, $start);
            $this->auditLog('document_analysis_failed', ['file' => $fileName, 'error' => $e->getMessage(), 'error_code' => $errorCode], $documentId);

            if ($this->wasDivertedToClearing($documentId)) {
                return;
            }

            if ($this->clearing->autoClearingEnabled()) {
                $this->clearing->moveToClearing($documentId, $errorCode, ['error' => $e->getMessage()], null);
            } else {
                $this->documents->updateStatus($documentId, 'error');
                $this->notify('error', 'Analyse fehlgeschlagen: ' . $fileName, $e->getMessage(), $documentId);
            }

            return;
        }

        $durationMs = (int) round((microtime(true) - $start) * 1000);
        $this->recordRun($documentId, true, $result, $extractedText, null, $model, $start);

        // Wurde das Dokument währenddessen per Notfall ins Clearing verschoben,
        // darf das Analyseergebnis die manuelle Bearbeitung nicht überschreiben.
        if ($this->wasDivertedToClearing($documentId)) {
            return;
        }

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

        unset($result['extracted_text']);
        $this->documents->updateAnalysis($documentId, [
            'document_type' => $documentType,
            'case_number' => $caseNumber,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'analysis_json' => json_encode($result, JSON_UNESCAPED_UNICODE),
            'analysis_model' => $model,
            'analysis_duration_ms' => $durationMs,
            'patient_key' => hash('sha256', implode('|', [(string) $lastName, (string) $firstName, (string) $birthDate, (string) $caseNumber])),
            'status' => 'analyzed',
        ]);
        $this->auditLog('document_analyzed', ['file' => $fileName], $documentId);

        // Formular-Erkennung: interaktive Dokumente (Anamnesebogen, Fragebogen …)
        // werden gekennzeichnet und anschließend im Detail analysiert.
        $this->handleFormDetection($documentId, $fileName, $result);

        // Clearing-Prüfung: unklare Ergebnisse werden nicht verworfen,
        // sondern zur manuellen Bearbeitung gesammelt.
        $confidence = $this->clearing->extractConfidence($result);
        $errorCode = $this->clearing->autoClearingEnabled() ? $this->clearing->evaluate($result, $confidence) : null;
        if ($errorCode !== null) {
            $this->clearing->moveToClearing($documentId, $errorCode, $result, $confidence);

            return;
        }

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
    }

    /** Prüft, ob das Dokument zwischenzeitlich (Notfall) ins Clearing verschoben wurde. */
    private function wasDivertedToClearing(int $documentId): bool
    {
        try {
            $document = $this->documents->findById($documentId);

            return $document !== null && (string) $document['status'] === 'clearing';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Kennzeichnet interaktive Dokumente und startet die Formularanalyse
     * (AcroForms haben Vorrang, sonst Vision-KI).
     *
     * @param array<string,mixed> $result
     */
    private function handleFormDetection(int $documentId, string $fileName, array $result): void
    {
        if (!$this->settings->getBool('forms.analysis_enabled', true)) {
            return;
        }

        $interactive = filter_var($result['interactive'] ?? false, FILTER_VALIDATE_BOOL);
        if (!$interactive) {
            return;
        }

        $confidence = isset($result['confidence']) && is_numeric($result['confidence']) ? (float) $result['confidence'] : null;
        $this->documents->updateFormStatus($documentId, 'detected', true);
        $this->auditLog('form_detected', ['file' => $fileName, 'confidence' => $confidence], $documentId);

        try {
            $this->formAnalysis->analyzeDocument($documentId);
            $this->documents->updateFormStatus($documentId, 'analyzed');
        } catch (\Throwable $e) {
            // Formularanalyse-Fehler stoppen den Signaturprozess nicht:
            // das Dokument bleibt als normales Dokument nutzbar.
            $this->documents->updateFormStatus($documentId, 'error', false);
            $this->notify('warning', 'Formularanalyse fehlgeschlagen: ' . $fileName, $e->getMessage(), $documentId);
        }
    }

    /**
     * Historisiert jeden Analyse-Lauf (auch für spätere Neuanalysen nachvollziehbar).
     *
     * @param array<string,mixed>|null $result
     */
    private function recordRun(int $documentId, bool $success, ?array $result, ?string $extractedText, ?string $error, string $model, float $start): void
    {
        try {
            $resultJson = null;
            if ($result !== null) {
                $stored = $result;
                unset($stored['extracted_text']);
                $resultJson = json_encode($stored, JSON_UNESCAPED_UNICODE);
            }
            $this->analysisRuns->create([
                'document_id' => $documentId,
                'run_mode' => 'initial',
                'success' => $success ? 1 : 0,
                'result_json' => $resultJson,
                'extracted_text' => $extractedText,
                'error_message' => $error,
                'analysis_model' => $model,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'triggered_by' => null,
            ]);
        } catch (\Throwable) {
            // Historisierung darf die Verarbeitung nicht verhindern.
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
