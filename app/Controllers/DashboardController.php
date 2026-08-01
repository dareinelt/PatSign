<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\SignatureRepository;
use App\Core\Request;
use App\Security\CsrfTokenManager;
use App\Services\DeviceService;
use App\Services\SettingsService;
use App\Services\SystemStatusService;

final class DashboardController extends BaseController
{
    public function __construct(
        View $view,
        private readonly DocumentRepository $documents,
        private readonly SignatureRepository $signatures,
        private readonly AuditLogRepository $auditLogs,
        private readonly SystemStatusService $systemStatus,
        private readonly SettingsService $settings,
        private readonly DeviceService $devices,
        private readonly CsrfTokenManager $csrf
    ) {
        parent::__construct($view);
    }

    public function index(): Response
    {
        return $this->render('dashboard.index', [
            'csrf' => $this->csrf->token(),
            'user' => $_SESSION['auth_user'] ?? [],
            'clinicName' => $this->settings->getString('general.clinic_name', $this->settings->getString('app.name', 'PatSign')),
            'waitingPatients' => $this->safeCall(fn () => $this->documents->waitingPatients()),
            'statusCounts' => $this->safeCall(fn () => $this->documents->countsByStatus()),
            'activities' => $this->safeCall(fn () => $this->auditLogs->latest(15)),
            'devices' => $this->safeCall(fn () => $this->devices->overview()),
            'systemChecks' => $this->systemStatus->checkAll(),
        ]);
    }

    public function data(): Response
    {
        return $this->json([
            'waitingPatients' => $this->safeCall(fn () => $this->documents->waitingPatients()),
            'statusCounts' => $this->safeCall(fn () => $this->documents->countsByStatus()),
            'activities' => $this->safeCall(fn () => $this->auditLogs->latest(15)),
            'systemChecks' => $this->systemStatus->checkAll(),
        ]);
    }

    /** Übersicht der heute unterschriebenen Dokumente mit Vorschau. */
    public function signedToday(): Response
    {
        return $this->render('dashboard.signed', [
            'csrf' => $this->csrf->token(),
            'user' => $_SESSION['auth_user'] ?? [],
            'clinicName' => $this->settings->getString('general.clinic_name', $this->settings->getString('app.name', 'PatSign')),
            'signedDocuments' => $this->safeCall(fn () => $this->documents->signedToday()),
        ]);
    }

    /** Streamt das PDF eines unterschriebenen Dokuments für die Vorschau. */
    public function signedDocument(Request $request): Response
    {
        $id = (int) $request->input('id');
        $document = $this->documents->findById($id);

        if ($document === null) {
            return new Response('Nicht gefunden', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $signature = $this->signatures->latestForDocument($id);
        if ($signature === null) {
            return new Response('Nicht gefunden', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        // Bevorzugt das signierte PDF; fällt auf das Originaldokument zurück,
        // falls der Export (z. B. Netzwerkfreigabe) nicht erreichbar ist.
        $path = (string) ($signature['signed_pdf_path'] ?? '');
        if ($path === '' || !is_file($path)) {
            $path = (string) $document['original_path'];
        }

        if (!is_file($path)) {
            return new Response('Datei nicht verfügbar', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="dokument.pdf"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function search(Request $request): Response
    {
        $term = trim((string) $request->input('q', ''));
        if ($term === '') {
            return $this->json(['results' => []]);
        }

        return $this->json(['results' => $this->safeCall(fn () => $this->documents->search($term))]);
    }

    private function safeCall(callable $fn): array
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return [];
        }
    }
}
