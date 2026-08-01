<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
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
        return $this->json(['message' => 'Import erfolgreich', 'path' => $path]);
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
