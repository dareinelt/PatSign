<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Security\CsrfTokenManager;
use App\Services\ClearingService;
use App\Services\SettingsService;

/**
 * Clearing-Bereich: manuelle Prüfung, Korrektur und Zuordnung von Dokumenten,
 * die durch die KI nicht eindeutig einer Patientenmappe zugeordnet werden
 * konnten. Zugriff nur für berechtigtes medizinisches Personal – alle
 * Änderungen werden serverseitig geprüft und revisionssicher protokolliert.
 */
final class ClearingController extends BaseController
{
    /** Rollen, die Clearing-Fälle einsehen und bearbeiten dürfen. */
    private const ALLOWED_ROLES = ['admin', 'operator'];

    public function __construct(
        View $view,
        private readonly ClearingService $clearing,
        private readonly SettingsService $settings,
        private readonly CsrfTokenManager $csrf
    ) {
        parent::__construct($view);
    }

    public function index(): Response
    {
        if (($denied = $this->requirePermission()) !== null) {
            return $denied;
        }

        return $this->render('clearing.index', [
            'csrf' => $this->csrf->token(),
            'user' => $_SESSION['auth_user'] ?? [],
            'clinicName' => $this->settings->getString('general.clinic_name', $this->settings->getString('app.name', 'PatSign')),
            'cases' => $this->safeCall(fn () => $this->clearing->listOpenCases()),
        ]);
    }

    /** Offene Fälle als JSON (Auto-Refresh der Übersicht). */
    public function data(): Response
    {
        if (($denied = $this->requirePermission(true)) !== null) {
            return $denied;
        }

        return $this->json(['cases' => $this->safeCall(fn () => $this->clearing->listOpenCases())]);
    }

    /** Anzahl offener Fälle (Navigations-Badge, für alle angemeldeten Benutzer). */
    public function count(): Response
    {
        try {
            return $this->json(['open' => $this->clearing->openCount()]);
        } catch (\Throwable) {
            return $this->json(['open' => 0]);
        }
    }

    public function detail(Request $request): Response
    {
        if (($denied = $this->requirePermission()) !== null) {
            return $denied;
        }

        try {
            $case = $this->clearing->caseDetail((int) $request->input('id'), $this->userId());
        } catch (\InvalidArgumentException) {
            return new Response('Nicht gefunden', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return $this->render('clearing.detail', [
            'csrf' => $this->csrf->token(),
            'user' => $_SESSION['auth_user'] ?? [],
            'clinicName' => $this->settings->getString('general.clinic_name', $this->settings->getString('app.name', 'PatSign')),
            'case' => $case,
            'isAdmin' => (($_SESSION['auth_user']['role'] ?? '') === 'admin'),
            'allowTemporaryFolders' => $this->settings->getBool('clearing.allow_temporary_folders', true),
            'maxAiAttempts' => $this->clearing->maxAiAttempts(),
        ]);
    }

    /** Streamt das Original-PDF eines Clearing-Falls für die Vorschau. */
    public function document(Request $request): Response
    {
        if (($denied = $this->requirePermission(true)) !== null) {
            return $denied;
        }

        try {
            $path = $this->clearing->documentPath((int) $request->input('id'));
        } catch (\Throwable) {
            return new Response('Nicht gefunden', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="dokument.pdf"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Manuell korrigierte Werte speichern. */
    public function update(Request $request): Response
    {
        if (($denied = $this->requirePermission(true)) !== null) {
            return $denied;
        }

        return $this->tryJson(function () use ($request): array {
            $this->clearing->updateValues((int) $request->input('id'), $request->all(), $this->userId());

            return ['message' => 'Werte gespeichert.'];
        });
    }

    /** Live-Suche nach bestehenden Patienten/Mappen. */
    public function searchPatients(Request $request): Response
    {
        if (($denied = $this->requirePermission(true)) !== null) {
            return $denied;
        }

        return $this->json(['results' => $this->safeCall(
            fn () => $this->clearing->searchPatients(trim((string) $request->input('q', '')))
        )]);
    }

    /** Ein oder mehrere Fälle einem bestehenden Patienten zuordnen. */
    public function assign(Request $request): Response
    {
        if (($denied = $this->requirePermission(true)) !== null) {
            return $denied;
        }

        return $this->tryJson(function () use ($request): array {
            $this->clearing->assignToExistingPatient(
                $this->caseIds($request),
                (string) $request->input('case_number', ''),
                $this->userId()
            );

            return ['message' => 'Dokument(e) zugeordnet und übernommen.'];
        });
    }

    /** Neue (ggf. temporäre) Patientenmappe erstellen und Fälle zuordnen. */
    public function createFolder(Request $request): Response
    {
        if (($denied = $this->requirePermission(true)) !== null) {
            return $denied;
        }

        return $this->tryJson(function () use ($request): array {
            $folder = $this->clearing->assignToNewFolder(
                $this->caseIds($request),
                [
                    'case_number' => $request->input('case_number', ''),
                    'first_name' => $request->input('first_name', ''),
                    'last_name' => $request->input('last_name', ''),
                    'birth_date' => $request->input('birth_date', ''),
                ],
                filter_var($request->input('temporary', false), FILTER_VALIDATE_BOOL),
                $this->userId()
            );

            return [
                'message' => ((int) ($folder['is_temporary'] ?? 0) === 1 ? 'Temporäre ' : '') . 'Patientenmappe erstellt und Dokument(e) übernommen.',
                'folder' => $folder,
            ];
        });
    }

    /** Endgültige Fallnummer bei einer temporären Mappe nachtragen. */
    public function addCaseNumber(Request $request): Response
    {
        if (($denied = $this->requirePermission(true)) !== null) {
            return $denied;
        }

        return $this->tryJson(function () use ($request): array {
            $this->clearing->addCaseNumberToFolder(
                (int) $request->input('folder_id'),
                (string) $request->input('case_number', ''),
                $this->userId()
            );

            return ['message' => 'Fallnummer ergänzt – Dokumente und Zuordnung wurden aktualisiert.'];
        });
    }

    /** KI-Analyse erneut starten (vision | analysis | both). */
    public function reanalyze(Request $request): Response
    {
        if (($denied = $this->requirePermission(true)) !== null) {
            return $denied;
        }

        return $this->tryJson(function () use ($request): array {
            $this->clearing->startReanalysis(
                (int) $request->input('id'),
                (string) $request->input('mode', 'both'),
                $this->userId()
            );

            return ['message' => 'Neuanalyse gestartet – das Ergebnis erscheint unter Benachrichtigungen.'];
        });
    }

    /** Fall endgültig abschließen. */
    public function complete(Request $request): Response
    {
        if (($denied = $this->requirePermission(true)) !== null) {
            return $denied;
        }

        return $this->tryJson(function () use ($request): array {
            $this->clearing->completeCase((int) $request->input('id'), $this->userId());

            return ['message' => 'Clearing-Fall abgeschlossen.'];
        });
    }

    /** Mehrfachauswahl archivieren. */
    public function archive(Request $request): Response
    {
        if (($denied = $this->requirePermission(true)) !== null) {
            return $denied;
        }

        return $this->tryJson(function () use ($request): array {
            $this->clearing->archiveCases($this->caseIds($request), $this->userId());

            return ['message' => 'Dokument(e) archiviert.'];
        });
    }

    // ------------------------------------------------------------------

    /** Serverseitige Berechtigungsprüfung für alle Clearing-Aktionen. */
    private function requirePermission(bool $asJson = false): ?Response
    {
        $role = (string) ($_SESSION['auth_user']['role'] ?? '');
        if (in_array($role, self::ALLOWED_ROLES, true)) {
            return null;
        }

        return $asJson
            ? $this->json(['error' => 'Keine Berechtigung für den Clearing-Bereich.'], 403)
            : new Response('Zugriff verweigert', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    private function userId(): ?int
    {
        return isset($_SESSION['auth_user']['id']) ? (int) $_SESSION['auth_user']['id'] : null;
    }

    /** @return list<int> */
    private function caseIds(Request $request): array
    {
        $raw = $request->input('ids', $request->input('id'));
        if (is_string($raw)) {
            $raw = array_filter(array_map('trim', explode(',', $raw)));
        }
        if (!is_array($raw)) {
            $raw = [];
        }
        $ids = array_values(array_unique(array_map('intval', $raw)));
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            throw new \InvalidArgumentException('Keine Clearing-Fälle ausgewählt.');
        }

        return $ids;
    }

    private function tryJson(callable $fn): Response
    {
        try {
            return $this->json($fn());
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable) {
            return $this->json(['error' => 'Aktion fehlgeschlagen.'], 500);
        }
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
