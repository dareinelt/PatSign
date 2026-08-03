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
use App\Services\ClearingService;
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
        private readonly ClearingService $clearing,
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
            'clearingStats' => $this->clearingStats(),
            'systemChecks' => $this->systemStatus->checkAll(),
        ]);
    }

    public function data(): Response
    {
        return $this->json([
            'waitingPatients' => $this->safeCall(fn () => $this->documents->waitingPatients()),
            'statusCounts' => $this->safeCall(fn () => $this->documents->countsByStatus()),
            'activities' => $this->safeCall(fn () => $this->auditLogs->latest(15)),
            'clearingStats' => $this->clearingStats(),
            'systemChecks' => $this->systemStatus->checkAll(),
        ]);
    }

    /** Patientenmappen mit offenen Unterschriften im konfigurierten Zeitraum (Overlay). */
    public function folders(): Response
    {
        $hours = max(1, $this->settings->getInt('dashboard.folder_overview_hours', 24));
        $folders = $this->safeCall(fn () => $this->documents->unsignedFolders($hours));

        $caseNumbers = array_values(array_filter(array_column($folders, 'case_number')));
        $documents = $this->safeCall(fn () => $this->documents->documentsForCaseNumbers($caseNumbers));

        $byCase = [];
        foreach ($documents as $document) {
            $byCase[(string) $document['case_number']][] = [
                'id' => (int) $document['id'],
                'document_type' => (string) ($document['document_type'] ?? ''),
                'status' => (string) $document['status'],
                'is_interactive' => (bool) ($document['is_interactive'] ?? false),
                'form_status' => (string) ($document['form_status'] ?? 'none'),
                'created_at' => (string) $document['created_at'],
            ];
        }

        foreach ($folders as &$folder) {
            $folder['documents'] = $byCase[(string) $folder['case_number']] ?? [];
        }
        unset($folder);

        return $this->json([
            'periodHours' => $hours,
            'folders' => $folders,
        ]);
    }

    /** Laufende KI-Analysen mit Original-Dateinamen und Fortschrittsschätzung. */
    public function analyzing(): Response
    {
        $documents = $this->safeCall(fn () => $this->documents->analyzingDocuments());
        $avgDurationMs = null;
        try {
            $avgDurationMs = $this->documents->averageAnalysisDurationMs();
        } catch (\Throwable) {
            // Ohne Historie wird der Fortschritt unbestimmt angezeigt.
        }

        $items = [];
        foreach ($documents as $document) {
            $elapsedSeconds = max(0, (int) ($document['elapsed_seconds'] ?? 0));
            $progress = null;
            if ($avgDurationMs !== null && $avgDurationMs > 0) {
                // Schätzung anhand der bisherigen Durchschnittsdauer, gedeckelt bei 95 %.
                $progress = min(0.95, ($elapsedSeconds * 1000) / $avgDurationMs);
            }
            $items[] = [
                'id' => (int) $document['id'],
                'file_name' => basename((string) $document['original_path']),
                'started_at' => (string) $document['updated_at'],
                'elapsed_seconds' => $elapsedSeconds,
                'progress' => $progress,
            ];
        }

        return $this->json([
            'documents' => $items,
            'count' => count($items),
        ]);
    }

    /**
     * Legt eine Patientenmappe manuell an (ohne importierte Dokumente), damit
     * anschließend direkt Vorlagen aus dem Dokumentenkatalog hinzugefügt
     * werden können. Ohne Fallnummer entsteht eine temporäre Mappe.
     */
    public function createFolder(Request $request): Response
    {
        $userId = isset($_SESSION['auth_user']['id']) ? (int) $_SESSION['auth_user']['id'] : null;

        try {
            $folder = $this->clearing->assignToNewFolder(
                [],
                [
                    'case_number' => $request->input('case_number', ''),
                    'first_name' => $request->input('first_name', ''),
                    'last_name' => $request->input('last_name', ''),
                    'birth_date' => $request->input('birth_date', ''),
                ],
                trim((string) $request->input('case_number', '')) === '',
                $userId
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable) {
            return $this->json(['error' => 'Patientenmappe konnte nicht angelegt werden.'], 500);
        }

        $temporary = (int) ($folder['is_temporary'] ?? 0) === 1;

        return $this->json([
            'message' => ($temporary ? 'Temporäre Patientenmappe' : 'Patientenmappe') . ' ' . (string) ($folder['case_number'] ?? '') . ' angelegt.',
            'folder' => [
                'case_number' => (string) ($folder['case_number'] ?? ''),
                'first_name' => (string) ($folder['first_name'] ?? ''),
                'last_name' => (string) ($folder['last_name'] ?? ''),
                'is_temporary' => $temporary,
            ],
        ]);
    }

    /**
     * Notfall: überspringt die KI-Analyse und verschiebt das Dokument direkt
     * ins Clearing zur manuellen Zuordnung.
     */    public function emergency(Request $request): Response
    {
        $id = (int) $request->input('document_id');
        $document = $this->documents->findById($id);
        if ($document === null) {
            return $this->json(['error' => 'Dokument nicht gefunden.'], 404);
        }

        if (!in_array((string) $document['status'], ['imported', 'analyzing'], true)) {
            return $this->json(['error' => 'Das Dokument befindet sich nicht mehr in der Analyse.'], 409);
        }

        $userId = isset($_SESSION['auth_user']['id']) ? (int) $_SESSION['auth_user']['id'] : null;
        try {
            $this->clearing->moveToClearing($id, 'MANUAL_EMERGENCY', [], null, $userId, false);
        } catch (\Throwable) {
            return $this->json(['error' => 'Dokument konnte nicht ins Clearing verschoben werden.'], 500);
        }

        return $this->json([
            'message' => basename((string) $document['original_path']) . ' wurde ins Clearing verschoben.',
        ]);
    }

    /** @return array<string,mixed> */
    private function clearingStats(): array
    {
        try {
            return $this->clearing->dashboardStats();
        } catch (\Throwable) {
            return [];
        }
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
