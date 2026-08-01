<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\AuditLogRepository;
use App\Repositories\DocumentRepository;
use App\Security\CsrfTokenManager;
use App\Services\DocumentAnalysisService;
use App\Services\PdfImportService;
use App\Services\SignatureService;

final class DocumentController extends BaseController
{
    public function __construct(
        \App\Core\View $view,
        private readonly PdfImportService $imports,
        private readonly DocumentAnalysisService $analysis,
        private readonly SignatureService $signature,
        private readonly DocumentRepository $documents,
        private readonly AuditLogRepository $auditLogs,
        private readonly Config $config,
        private readonly CsrfTokenManager $csrf
    ) {
        parent::__construct($view);
    }

    public function index(): Response
    {
        return $this->render('documents.index', ['csrf' => $this->csrf->token()]);
    }

    public function upload(Request $request): Response
    {
        $file = $request->files()['document'] ?? null;
        if (!is_array($file)) {
            return $this->json(['error' => 'Keine Datei übermittelt'], 422);
        }

        $path = $this->imports->importUpload($file);
        $documentUuid = $this->generateUuid();
        $id = $this->documents->create([
            'document_id' => $documentUuid,
            'original_path' => $path,
            'document_type' => 'Unbekannt',
            'case_number' => null,
            'first_name' => null,
            'last_name' => null,
            'birth_date' => null,
            'analysis_json' => null,
            'prompt_version_vision' => null,
            'prompt_version_analysis' => null,
            'analysis_model' => null,
            'analysis_duration_ms' => null,
            'patient_key' => hash('sha256', $documentUuid),
            'status' => 'imported',
        ]);
        $this->auditLog('document_imported', ['file' => basename($path)], $id);

        $analysisResult = null;
        $warning = null;
        try {
            $this->documents->updateStatus($id, 'analyzing');
            $start = microtime(true);
            $analysisResult = $this->analysis->analyze($path);
            $durationMs = (int) round((microtime(true) - $start) * 1000);

            $caseNumber = isset($analysisResult['case_number']) && is_string($analysisResult['case_number']) && $analysisResult['case_number'] !== ''
                ? $analysisResult['case_number']
                : null;
            $firstName = isset($analysisResult['first_name']) && is_string($analysisResult['first_name']) ? $analysisResult['first_name'] : null;
            $lastName = isset($analysisResult['last_name']) && is_string($analysisResult['last_name']) ? $analysisResult['last_name'] : null;
            $birthDate = isset($analysisResult['birth_date']) && is_string($analysisResult['birth_date']) && $analysisResult['birth_date'] !== ''
                ? $analysisResult['birth_date']
                : null;

            $this->documents->updateAnalysis($id, [
                'document_type' => isset($analysisResult['document_type']) && is_string($analysisResult['document_type']) && $analysisResult['document_type'] !== ''
                    ? $analysisResult['document_type']
                    : 'Unbekannt',
                'case_number' => $caseNumber,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'birth_date' => $birthDate,
                'analysis_json' => json_encode($analysisResult, JSON_UNESCAPED_UNICODE),
                'analysis_model' => $_ENV['ANALYSIS_MODEL'] ?? 'gemma-4-e4b',
                'analysis_duration_ms' => $durationMs,
                'patient_key' => hash('sha256', implode('|', [(string) $lastName, (string) $firstName, (string) $birthDate, (string) $caseNumber])),
                'status' => 'analyzed',
            ]);
            $this->auditLog('document_analyzed', ['file' => basename($path)], $id);
        } catch (\Throwable $e) {
            $this->documents->updateStatus($id, 'error');
            $warning = 'KI-Analyse fehlgeschlagen: ' . $e->getMessage();
            $this->auditLog('document_analysis_failed', ['file' => basename($path), 'error' => $e->getMessage()], $id);
        }

        return $this->json(array_filter([
            'message' => 'Import erfolgreich',
            'path' => $path,
            'document_id' => $documentUuid,
            'analysis' => $analysisResult,
            'warning' => $warning,
        ], static fn ($value) => $value !== null));
    }

    /** @param array<string,mixed> $context */
    private function auditLog(string $event, array $context, int $documentId): void
    {
        try {
            $this->auditLogs->log(
                $event,
                $context,
                isset($_SESSION['auth_user']['id']) ? (int) $_SESSION['auth_user']['id'] : null,
                $documentId
            );
        } catch (\Throwable) {
            // Audit-Logging darf den Import nicht verhindern.
        }
    }

    private function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public function scanWatchFolder(): Response
    {
        return $this->json(['files' => $this->imports->importFromWatchFolder()]);
    }

    public function analyze(Request $request): Response
    {
        $path = (string) $request->input('path');
        return $this->json($this->analysis->analyze($path));
    }

    public function sign(Request $request): Response
    {
        $metadata = $this->signature->completionPageMetadata([
            'document_id' => (string) $request->input('document_id'),
            'original_document_reference' => (string) $request->input('original_document_reference'),
            'patient_name' => (string) $request->input('patient_name'),
            'case_number' => (string) $request->input('case_number'),
            'document_type' => (string) $request->input('document_type', 'Unbekannt'),
            'email_consent' => filter_var($request->input('email_consent', false), FILTER_VALIDATE_BOOL),
            'email' => (string) $request->input('email', ''),
            'clinic' => (string) $request->input('clinic', ''),
            'operator' => (string) $request->input('operator', ''),
            'signature_data' => (string) $request->input('signature_data', ''),
        ]);

        return $this->json([
            'message' => 'Abschlussseite-Metadaten erzeugt',
            'metadata' => $metadata,
            'network_share' => $this->config->get('app.network_share_path'),
        ]);
    }
}
