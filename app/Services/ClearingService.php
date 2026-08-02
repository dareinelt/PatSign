<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AnalysisRunRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\ClearingCaseRepository;
use App\Repositories\ClearingErrorReasonRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\PatientFolderRepository;
use App\Support\BackgroundProcess;
use InvalidArgumentException;
use RuntimeException;

/**
 * Clearing-Bereich: sammelt Dokumente, die die KI nicht eindeutig zuordnen
 * konnte, und stellt die manuelle Prüfung, Korrektur und Zuordnung bereit.
 * Alle Schritte werden revisionssicher in der Fall-Historie und im Audit-Log
 * protokolliert.
 */
final class ClearingService
{
    /** Felder, die im Clearing manuell korrigiert werden dürfen. */
    private const EDITABLE_FIELDS = ['document_type', 'case_number', 'first_name', 'last_name', 'birth_date'];

    public function __construct(
        private readonly ClearingCaseRepository $cases,
        private readonly PatientFolderRepository $folders,
        private readonly ClearingErrorReasonRepository $errorReasons,
        private readonly AnalysisRunRepository $analysisRuns,
        private readonly DocumentRepository $documents,
        private readonly AuditLogRepository $auditLogs,
        private readonly NotificationRepository $notifications,
        private readonly SettingsService $settings,
        private readonly string $basePath
    ) {}

    // ------------------------------------------------------------------
    // Automatische Überführung ins Clearing
    // ------------------------------------------------------------------

    public function autoClearingEnabled(): bool
    {
        return $this->settings->getBool('clearing.auto_clearing_enabled', true);
    }

    public function confidenceThreshold(): float
    {
        return max(0.0, min(1.0, (float) $this->settings->get('clearing.confidence_threshold', 0.7)));
    }

    public function maxAiAttempts(): int
    {
        return max(1, $this->settings->getInt('clearing.max_ai_attempts', 3));
    }

    /**
     * Prüft ein Analyseergebnis auf Clearing-Bedingungen.
     * Liefert den standardisierten Fehlercode oder null, wenn das Dokument
     * regulär weiterverarbeitet werden kann.
     *
     * @param array<string,mixed> $result
     */
    public function evaluate(array $result, ?float $confidence): ?string
    {
        $caseNumber = isset($result['case_number']) && is_string($result['case_number']) ? trim($result['case_number']) : '';
        $firstName = isset($result['first_name']) && is_string($result['first_name']) ? trim($result['first_name']) : '';
        $lastName = isset($result['last_name']) && is_string($result['last_name']) ? trim($result['last_name']) : '';
        $birthDate = isset($result['birth_date']) && is_string($result['birth_date']) ? trim($result['birth_date']) : '';
        $documentType = isset($result['document_type']) && is_string($result['document_type']) ? trim($result['document_type']) : '';

        if ($caseNumber === '') {
            // Ohne Fallnummer ggf. über Name + Geburtsdatum mehrere Kandidaten?
            if ($lastName !== '' && $birthDate !== ''
                && count($this->documents->distinctCaseNumbersByPatient($lastName, $firstName, $birthDate)) > 1) {
                return 'MULTIPLE_MATCHES';
            }

            return 'NO_CASE_NUMBER';
        }

        if (!$this->isValidCaseNumber($caseNumber)) {
            return 'INVALID_CASE_NUMBER';
        }

        if ($firstName === '' || $lastName === '' || $birthDate === '') {
            return 'MISSING_PATIENT_DATA';
        }

        if ($documentType === '' || $documentType === 'Unbekannt') {
            return 'UNKNOWN_DOCUMENT_TYPE';
        }

        if ($confidence !== null && $confidence < $this->confidenceThreshold()) {
            return 'LOW_CONFIDENCE';
        }

        return null;
    }

    /** Gesamt-Konfidenz eines Analyseergebnisses (niedrigster Einzelwert). */
    public function extractConfidence(array $result): ?float
    {
        $values = [];
        foreach (['case_number_confidence', 'confidence'] as $key) {
            if (isset($result[$key]) && is_numeric($result[$key])) {
                $values[] = (float) $result[$key];
            }
        }

        return $values === [] ? null : max(0.0, min(1.0, min($values)));
    }

    /**
     * Verschiebt ein Dokument ins Clearing (legt einen offenen Fall an).
     *
     * @param array<string,mixed> $detectedValues
     */
    public function moveToClearing(int $documentId, string $errorCode, array $detectedValues, ?float $confidence, ?int $userId = null, bool $allowAutoReanalysis = true): int
    {
        $existing = $this->cases->findOpenByDocumentId($documentId);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $caseId = $this->cases->create([
            'case_uuid' => $this->uuid(),
            'document_id' => $documentId,
            'status' => 'open',
            'error_code' => $errorCode,
            'ai_confidence' => $confidence,
            'detected_values' => json_encode($detectedValues, JSON_UNESCAPED_UNICODE),
        ]);
        $this->documents->updateStatus($documentId, 'clearing');
        $this->cases->addHistory($caseId, 'clearing_created', null, [
            'error_code' => $errorCode,
            'ai_confidence' => $confidence,
            'detected_values' => $detectedValues,
        ], $userId);
        $this->audit('document_moved_to_clearing', [
            'error_code' => $errorCode,
            'ai_confidence' => $confidence,
        ], $documentId, $userId);
        $this->notify(
            'warning',
            'Dokument im Clearing',
            'Ein Dokument konnte nicht automatisch zugeordnet werden (' . $this->errorLabel($errorCode) . ').',
            $documentId
        );

        // Optionale automatische Neuanalyse, solange Versuche übrig sind.
        if ($allowAutoReanalysis
            && $this->settings->getBool('clearing.auto_reanalysis', false)
            && $this->analysisRuns->countForDocument($documentId) < $this->maxAiAttempts()) {
            $this->startReanalysis($caseId, 'both', null, true);
        }

        return $caseId;
    }

    public function errorLabel(string $code): string
    {
        return $this->errorReasons->labels()[$code] ?? $code;
    }

    // ------------------------------------------------------------------
    // Übersicht und Detail
    // ------------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function listOpenCases(): array
    {
        return array_map(function (array $row): array {
            $detected = $this->decode($row['detected_values'] ?? null);
            $corrected = $this->decode($row['corrected_values'] ?? null);
            $effective = array_merge($detected, array_filter($corrected, static fn ($v) => $v !== null && $v !== ''));

            return [
                'id' => (int) $row['id'],
                'created_at' => (string) $row['created_at'],
                'file_name' => basename((string) $row['original_path']),
                'document_type' => (string) ($effective['document_type'] ?? $row['document_type'] ?? 'Unbekannt'),
                'case_number' => $effective['case_number'] ?? $row['case_number'],
                'first_name' => $effective['first_name'] ?? $row['first_name'],
                'last_name' => $effective['last_name'] ?? $row['last_name'],
                'birth_date' => $effective['birth_date'] ?? $row['birth_date'],
                'ai_confidence' => $row['ai_confidence'] !== null ? (float) $row['ai_confidence'] : null,
                'error_code' => (string) $row['error_code'],
                'error_label' => (string) ($row['error_label'] ?? $row['error_code']),
                'status' => (string) $row['status'],
            ];
        }, $this->cases->listOpen());
    }

    /** @return array<string,mixed> */
    public function caseDetail(int $caseId, ?int $userId, bool $logOpen = true): array
    {
        $case = $this->cases->findById($caseId);
        if ($case === null) {
            throw new InvalidArgumentException('Clearing-Fall nicht gefunden.');
        }

        if ($logOpen) {
            $this->audit('clearing_case_opened', ['clearing_case_id' => $caseId], (int) $case['document_id'], $userId);
        }

        $case['detected'] = $this->decode($case['detected_values'] ?? null);
        $case['corrected'] = $this->decode($case['corrected_values'] ?? null);
        $case['effective'] = array_merge(
            array_intersect_key($case['detected'], array_flip(self::EDITABLE_FIELDS)),
            array_filter($case['corrected'], static fn ($v) => $v !== null && $v !== '')
        );
        $case['history'] = $this->cases->history($caseId);
        $case['analysis_runs'] = $this->analysisRuns->forDocument((int) $case['document_id']);
        $case['error_label'] = $this->errorLabel((string) $case['error_code']);

        return $case;
    }

    /** Original-PDF eines Clearing-Falls (mit Zugriffsprüfung). */
    public function documentPath(int $caseId): string
    {
        $case = $this->cases->findById($caseId);
        if ($case === null) {
            throw new InvalidArgumentException('Clearing-Fall nicht gefunden.');
        }

        $path = (string) $case['original_path'];
        if (!is_file($path)) {
            throw new RuntimeException('Datei nicht verfügbar.');
        }

        return $path;
    }

    // ------------------------------------------------------------------
    // Manuelle Korrektur
    // ------------------------------------------------------------------

    /**
     * Speichert manuell korrigierte Werte (serverseitig validiert).
     *
     * @param array<string,mixed> $input
     */
    public function updateValues(int $caseId, array $input, ?int $userId): void
    {
        $case = $this->caseDetail($caseId, $userId, false);
        $this->assertOpen($case);

        $values = $this->sanitizeValues($input);
        $old = $case['corrected'];
        $merged = array_merge($old, $values);

        $this->cases->updateCorrectedValues($caseId, json_encode($merged, JSON_UNESCAPED_UNICODE), $userId);
        $this->cases->addHistory($caseId, 'values_updated', $old, $merged, $userId);
        $this->audit('clearing_values_updated', ['clearing_case_id' => $caseId, 'fields' => array_keys($values)], (int) $case['document_id'], $userId);
    }

    // ------------------------------------------------------------------
    // Patientenzuordnung
    // ------------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function searchPatients(string $term): array
    {
        return $term === '' ? [] : $this->folders->search($term);
    }

    /**
     * Ordnet Clearing-Fälle einem bestehenden Patienten (per Fallnummer) zu.
     *
     * @param list<int> $caseIds
     */
    public function assignToExistingPatient(array $caseIds, string $caseNumber, ?int $userId): void
    {
        $caseNumber = trim($caseNumber);
        if ($caseNumber === '') {
            throw new InvalidArgumentException('Fallnummer erforderlich.');
        }

        $folder = $this->folders->findByCaseNumber($caseNumber);
        foreach ($caseIds as $caseId) {
            $this->completeAssignment((int) $caseId, $caseNumber, $folder !== null ? (int) $folder['id'] : null, $userId, 'assigned_existing');
        }
    }

    /**
     * Legt eine neue (ggf. temporäre) Patientenmappe an und ordnet die Fälle zu.
     *
     * @param list<int> $caseIds
     * @param array<string,mixed> $patient
     * @return array<string,mixed> Die angelegte Mappe
     */
    public function assignToNewFolder(array $caseIds, array $patient, bool $temporary, ?int $userId): array
    {
        $firstName = trim((string) ($patient['first_name'] ?? ''));
        $lastName = trim((string) ($patient['last_name'] ?? ''));
        $birthDate = $this->normalizeBirthDate((string) ($patient['birth_date'] ?? ''));
        $caseNumber = trim((string) ($patient['case_number'] ?? ''));

        if ($firstName === '' || $lastName === '' || $birthDate === null) {
            throw new InvalidArgumentException('Vorname, Nachname und Geburtsdatum sind erforderlich.');
        }

        if ($caseNumber === '') {
            if (!$this->settings->getBool('clearing.allow_temporary_folders', true)) {
                throw new InvalidArgumentException('Temporäre Patientenmappen sind deaktiviert. Bitte Fallnummer angeben.');
            }
            $temporary = true;
            $caseNumber = $this->folders->nextTemporaryCaseNumber();
        } elseif (!$this->isValidCaseNumber($caseNumber)) {
            throw new InvalidArgumentException('Ungültige Fallnummer.');
        }

        $folderId = $this->folders->create([
            'folder_uuid' => $this->uuid(),
            'case_number' => $caseNumber,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'is_temporary' => $temporary ? 1 : 0,
            'created_by' => $userId,
        ]);
        $this->audit($temporary ? 'temporary_folder_created' : 'patient_folder_created', [
            'folder_id' => $folderId,
            'case_number' => $caseNumber,
        ], null, $userId);

        foreach ($caseIds as $caseId) {
            $this->completeAssignment((int) $caseId, $caseNumber, $folderId, $userId, $temporary ? 'assigned_new_temporary' : 'assigned_new_folder');
        }

        return (array) $this->folders->findById($folderId);
    }

    /**
     * Trägt bei einer temporären Mappe die endgültige Fallnummer nach:
     * Mappe und alle zugehörigen Dokumente werden aktualisiert, Dateien
     * umbenannt, die Historie bleibt erhalten.
     */
    public function addCaseNumberToFolder(int $folderId, string $caseNumber, ?int $userId): void
    {
        $caseNumber = trim($caseNumber);
        if (!$this->isValidCaseNumber($caseNumber)) {
            throw new InvalidArgumentException('Ungültige Fallnummer.');
        }

        $folder = $this->folders->findById($folderId);
        if ($folder === null) {
            throw new InvalidArgumentException('Patientenmappe nicht gefunden.');
        }

        $oldCaseNumber = (string) ($folder['case_number'] ?? '');
        $this->folders->updateCaseNumber($folderId, $caseNumber, false);
        $this->documents->reassignCaseNumber($folderId, $oldCaseNumber, $caseNumber);

        // Dateien umbenennen, wenn der Dateiname die temporäre Nummer enthält.
        foreach ($this->documents->findByFolderId($folderId) as $document) {
            $this->renameDocumentFile((int) $document['id'], (string) $document['original_path'], $oldCaseNumber, $caseNumber);
        }

        $this->audit('case_number_added', [
            'folder_id' => $folderId,
            'old_case_number' => $oldCaseNumber,
            'new_case_number' => $caseNumber,
        ], null, $userId);
    }

    /**
     * Archiviert Clearing-Fälle samt Dokumenten (Mehrfachauswahl).
     *
     * @param list<int> $caseIds
     */
    public function archiveCases(array $caseIds, ?int $userId): void
    {
        foreach ($caseIds as $caseId) {
            $case = $this->cases->findById((int) $caseId);
            if ($case === null) {
                continue;
            }
            $this->assertOpen($case);
            $this->documents->updateStatus((int) $case['document_id'], 'archived');
            $this->cases->updateStatus((int) $case['id'], 'completed', $userId);
            $this->cases->addHistory((int) $case['id'], 'archived', null, null, $userId);
            $this->audit('clearing_document_archived', ['clearing_case_id' => (int) $case['id']], (int) $case['document_id'], $userId);
        }
    }

    /** Schließt einen zugeordneten Fall endgültig ab. */
    public function completeCase(int $caseId, ?int $userId): void
    {
        $case = $this->cases->findById($caseId);
        if ($case === null) {
            throw new InvalidArgumentException('Clearing-Fall nicht gefunden.');
        }
        if (!in_array((string) $case['status'], ['assigned', 'in_progress', 'open'], true)) {
            throw new InvalidArgumentException('Fall ist bereits abgeschlossen.');
        }

        $this->cases->updateStatus($caseId, 'completed', $userId);
        $this->cases->addHistory($caseId, 'completed', null, null, $userId);
        $this->audit('clearing_completed', ['clearing_case_id' => $caseId], (int) $case['document_id'], $userId);
    }

    // ------------------------------------------------------------------
    // KI erneut ausführen
    // ------------------------------------------------------------------

    /**
     * Startet die Neuanalyse asynchron im Hintergrund.
     * Modi: vision (OCR + Analyse), analysis (nur Analyse auf letztem Text), both.
     */
    public function startReanalysis(int $caseId, string $mode, ?int $userId, bool $automatic = false): void
    {
        if (!in_array($mode, ['vision', 'analysis', 'both'], true)) {
            throw new InvalidArgumentException('Unbekannter Analysemodus.');
        }

        $case = $this->cases->findById($caseId);
        if ($case === null) {
            throw new InvalidArgumentException('Clearing-Fall nicht gefunden.');
        }
        $this->assertOpen($case);

        $documentId = (int) $case['document_id'];
        if ($this->analysisRuns->countForDocument($documentId) >= $this->maxAiAttempts()) {
            throw new InvalidArgumentException('Maximale Anzahl an KI-Versuchen erreicht (' . $this->maxAiAttempts() . ').');
        }

        $this->cases->addHistory($caseId, 'reanalysis_started', null, ['mode' => $mode, 'automatic' => $automatic], $userId);
        $this->audit('clearing_reanalysis_started', ['clearing_case_id' => $caseId, 'mode' => $mode, 'automatic' => $automatic], $documentId, $userId);

        BackgroundProcess::runPhpScript($this->basePath . '/bin/reanalyze-document.php', [
            (string) $caseId,
            $mode,
            (string) ($userId ?? 0),
        ]);
    }

    /**
     * Führt die Neuanalyse aus (läuft im CLI-Worker). Vorherige Ergebnisse
     * bleiben als document_analysis_runs historisch erhalten.
     */
    public function performReanalysis(int $caseId, string $mode, ?int $userId, DocumentAnalysisService $analysis): void
    {
        $case = $this->cases->findById($caseId);
        if ($case === null) {
            return;
        }
        $documentId = (int) $case['document_id'];
        $path = (string) $case['original_path'];
        $fileName = basename($path);
        $model = $this->settings->getString('ai.analysis.model', (string) ($_ENV['ANALYSIS_MODEL'] ?? ''));

        $start = microtime(true);
        try {
            if ($mode === 'analysis') {
                $text = $this->analysisRuns->latestExtractedText($documentId);
                if ($text === null) {
                    throw new RuntimeException('Kein extrahierter Text vorhanden – bitte Vision erneut ausführen.');
                }
                $result = $analysis->analyzeText($text);
                $result['extracted_text'] = $text;
            } else {
                $result = $analysis->analyze($path);
            }
            $durationMs = (int) round((microtime(true) - $start) * 1000);
            $extractedText = isset($result['extracted_text']) && is_string($result['extracted_text']) ? $result['extracted_text'] : null;
            unset($result['extracted_text']);

            $this->analysisRuns->create([
                'document_id' => $documentId,
                'run_mode' => $mode,
                'success' => 1,
                'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE),
                'extracted_text' => $extractedText,
                'error_message' => null,
                'analysis_model' => $model,
                'duration_ms' => $durationMs,
                'triggered_by' => $userId,
            ]);

            $confidence = $this->extractConfidence($result);
            $errorCode = $this->evaluate($result, $confidence) ?? 'LOW_CONFIDENCE';
            $this->cases->updateDetectedValues($caseId, json_encode($result, JSON_UNESCAPED_UNICODE), $confidence, $errorCode);
            $this->documents->updateAnalysisValues($documentId, $result, $model);
            $this->cases->addHistory($caseId, 'reanalysis_succeeded', null, ['mode' => $mode, 'result' => $result, 'confidence' => $confidence], $userId);
            $this->audit('clearing_reanalysis_succeeded', ['clearing_case_id' => $caseId, 'mode' => $mode], $documentId, $userId);
            $this->notify('success', 'Neuanalyse abgeschlossen: ' . $fileName, 'Die KI-Neuanalyse wurde abgeschlossen. Ergebnis im Clearing prüfen.', $documentId);
        } catch (\Throwable $e) {
            $this->analysisRuns->create([
                'document_id' => $documentId,
                'run_mode' => $mode,
                'success' => 0,
                'result_json' => null,
                'extracted_text' => null,
                'error_message' => $e->getMessage(),
                'analysis_model' => $model,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'triggered_by' => $userId,
            ]);
            $this->cases->addHistory($caseId, 'reanalysis_failed', null, ['mode' => $mode, 'error' => $e->getMessage()], $userId);
            $this->audit('clearing_reanalysis_failed', ['clearing_case_id' => $caseId, 'mode' => $mode, 'error' => $e->getMessage()], $documentId, $userId);
            $this->notify('error', 'Neuanalyse fehlgeschlagen: ' . $fileName, $e->getMessage(), $documentId);
        }
    }

    // ------------------------------------------------------------------
    // Statistiken
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    public function dashboardStats(): array
    {
        $stats = $this->cases->dashboardStats();
        $stats['top_error_reasons'] = $this->cases->topErrorReasons();
        $stats['manual_assignments'] = $this->cases->countHistoryEvents('assigned');
        $stats['successful_reanalyses'] = $this->cases->countHistoryEvents('reanalysis_succeeded');

        return $stats;
    }

    public function openCount(): int
    {
        return $this->cases->countOpen();
    }

    // ------------------------------------------------------------------
    // Intern
    // ------------------------------------------------------------------

    /**
     * Übernimmt einen Fall in die Ziel-Patientenmappe: Dokument aktualisieren,
     * Clearing verlassen, regulärer Workflow läuft weiter.
     */
    private function completeAssignment(int $caseId, string $caseNumber, ?int $folderId, ?int $userId, string $historyEvent): void
    {
        $case = $this->caseDetail($caseId, $userId, false);
        $this->assertOpen($case);
        $documentId = (int) $case['document_id'];

        $folder = $folderId !== null ? $this->folders->findById($folderId) : null;
        $values = $case['effective'];
        $firstName = trim((string) ($values['first_name'] ?? ($folder['first_name'] ?? '')));
        $lastName = trim((string) ($values['last_name'] ?? ($folder['last_name'] ?? '')));
        $birthDate = $this->normalizeBirthDate((string) ($values['birth_date'] ?? ($folder['birth_date'] ?? '')));
        $documentType = trim((string) ($values['document_type'] ?? 'Unbekannt')) ?: 'Unbekannt';

        $this->documents->applyClearingAssignment($documentId, [
            'document_type' => $documentType,
            'case_number' => $caseNumber,
            'first_name' => $firstName !== '' ? $firstName : null,
            'last_name' => $lastName !== '' ? $lastName : null,
            'birth_date' => $birthDate,
            'patient_key' => hash('sha256', implode('|', [$lastName, $firstName, (string) $birthDate, $caseNumber])),
            'patient_folder_id' => $folderId,
            'status' => 'analyzed',
        ]);

        if ($folderId !== null) {
            $this->cases->assignFolder($caseId, $folderId);
        }
        $this->cases->updateStatus($caseId, 'assigned', $userId);
        $this->cases->addHistory($caseId, 'assigned', null, [
            'assignment' => $historyEvent,
            'case_number' => $caseNumber,
            'folder_id' => $folderId,
        ], $userId);
        $this->audit('clearing_document_assigned', [
            'clearing_case_id' => $caseId,
            'case_number' => $caseNumber,
            'folder_id' => $folderId,
        ], $documentId, $userId);
        $this->audit('clearing_document_released', ['clearing_case_id' => $caseId], $documentId, $userId);
    }

    /** @param array<string,mixed> $case */
    private function assertOpen(array $case): void
    {
        if (!in_array((string) $case['status'], ['open', 'in_progress'], true)) {
            throw new InvalidArgumentException('Clearing-Fall ist nicht mehr offen.');
        }
    }

    /**
     * Validiert manuell eingegebene Werte serverseitig.
     *
     * @param array<string,mixed> $input
     * @return array<string,string|null>
     */
    private function sanitizeValues(array $input): array
    {
        $values = [];
        foreach (self::EDITABLE_FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $value = trim((string) $input[$field]);
            if ($field === 'case_number' && $value !== '' && !$this->isValidCaseNumber($value)) {
                throw new InvalidArgumentException('Ungültige Fallnummer.');
            }
            if ($field === 'birth_date' && $value !== '') {
                $normalized = $this->normalizeBirthDate($value);
                if ($normalized === null) {
                    throw new InvalidArgumentException('Ungültiges Geburtsdatum.');
                }
                $value = $normalized;
            }
            if (in_array($field, ['first_name', 'last_name', 'document_type'], true) && mb_strlen($value) > 120) {
                throw new InvalidArgumentException('Wert zu lang: ' . $field);
            }
            $values[$field] = $value !== '' ? $value : null;
        }

        if ($values === []) {
            throw new InvalidArgumentException('Keine Werte übermittelt.');
        }

        return $values;
    }

    /** Fallnummern: 8-stellig, "9" + zwei Jahresziffern (aktuell oder 2 Vorjahre). */
    public function isValidCaseNumber(string $caseNumber): bool
    {
        if (preg_match('/^9\d{7}$/', $caseNumber) !== 1) {
            return false;
        }

        $year = (int) date('Y');
        foreach ([$year, $year - 1, $year - 2] as $y) {
            if (str_starts_with($caseNumber, '9' . substr((string) $y, -2))) {
                return true;
            }
        }

        return false;
    }

    /** Akzeptiert TT.MM.JJJJ und JJJJ-MM-TT, liefert JJJJ-MM-TT. */
    private function normalizeBirthDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? $m[3] . '-' . $m[2] . '-' . $m[1] : null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
        }

        return null;
    }

    /** Benennt die Datei um, wenn sie die alte (temporäre) Fallnummer enthält. */
    private function renameDocumentFile(int $documentId, string $path, string $oldCaseNumber, string $newCaseNumber): void
    {
        if ($oldCaseNumber === '' || !is_file($path) || !str_contains(basename($path), $oldCaseNumber)) {
            return;
        }

        $newPath = dirname($path) . '/' . str_replace($oldCaseNumber, $newCaseNumber, basename($path));
        if (!is_file($newPath) && @rename($path, $newPath)) {
            $this->documents->updateOriginalPath($documentId, $newPath);
            $this->audit('clearing_document_renamed', ['old' => basename($path), 'new' => basename($newPath)], $documentId, null);
        }
    }

    /** @param array<string,mixed> $context */
    private function audit(string $event, array $context, ?int $documentId, ?int $userId): void
    {
        try {
            $this->auditLogs->log($event, $context, $userId, $documentId);
        } catch (\Throwable) {
            // Audit-Logging darf die Verarbeitung nicht verhindern.
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

    /** @return array<string,mixed> */
    private function decode(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
