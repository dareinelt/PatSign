<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\DocumentTypeRepository;
use App\Repositories\DocumentTemplateRepository;
use App\Security\CsrfTokenManager;
use App\Services\Catalog\DocumentCatalogService;
use App\Services\SettingsService;

/**
 * Dokumentenkatalog: Administration der PDF-Vorlagen (Rollen "admin" und
 * "dokumentenmanagement", serverseitig über die RouteGuardMiddleware
 * durchgesetzt) sowie Personal-Endpunkte zum Hinzufügen, Entfernen und
 * Umsortieren von Katalogdokumenten in Patientenmappen.
 *
 * Vorlagen- und Dokumentdateien werden ausschließlich über diese
 * authentifizierten Endpunkte ausgeliefert, niemals über öffentliche URLs.
 */
final class DocumentCatalogController extends BaseController
{
    public function __construct(
        View $view,
        private readonly DocumentCatalogService $catalog,
        private readonly DocumentTemplateRepository $templates,
        private readonly DocumentTypeRepository $documentTypes,
        private readonly SettingsService $settings,
        private readonly CsrfTokenManager $csrf
    ) {
        parent::__construct($view);
    }

    /* ------------------------------------------------------------------ */
    /* Administration                                                      */
    /* ------------------------------------------------------------------ */

    public function adminIndex(): Response
    {
        $user = $_SESSION['auth_user'] ?? [];
        $templates = $this->templates->all();
        $versions = [];
        foreach ($templates as $template) {
            $versions[(int) $template['id']] = $this->templates->versions((int) $template['id']);
        }

        return $this->render('admin.document_catalog', [
            'csrf' => $this->csrf->token(),
            'user' => $user,
            'section' => 'document-catalog',
            'sections' => AdminController::sectionsForRole((string) ($user['role'] ?? '')),
            'sectionTitle' => 'Dokumentenkatalog',
            'settings' => $this->settings,
            'flash' => $this->pullFlash(),
            'templates' => $templates,
            'templateVersions' => $versions,
            'categories' => $this->templates->categories(),
            'documentTypes' => $this->documentTypes->all(),
            'placeholders' => DocumentCatalogService::PLACEHOLDERS,
        ]);
    }

    /** Neue Vorlage anlegen (PDF-Upload, serverseitig validiert). */
    public function save(Request $request): Response
    {
        $name = trim((string) $request->input('name'));
        $file = $request->files()['template_file'] ?? null;

        if ($name === '' || !is_array($file)) {
            $this->flash('Bitte Bezeichnung und PDF-Datei angeben.', 'error');

            return Response::redirect('/admin/document-catalog');
        }

        try {
            $this->catalog->createTemplate($file, [
                'name' => $name,
                'description' => trim((string) $request->input('description', '')) ?: null,
                'document_type' => trim((string) $request->input('document_type', 'Unbekannt')) ?: 'Unbekannt',
                'category_id' => ((int) $request->input('category_id')) ?: null,
                'is_active' => filter_var($request->input('is_active', true), FILTER_VALIDATE_BOOL),
            ], $this->userId());
            $this->flash('Dokumentvorlage angelegt.');
        } catch (\Throwable $e) {
            $this->flash('Vorlage konnte nicht angelegt werden: ' . $e->getMessage(), 'error');
        }

        return Response::redirect('/admin/document-catalog');
    }

    /** Metadaten einer Vorlage bearbeiten. */
    public function update(Request $request): Response
    {
        $id = (int) $request->input('id');
        $name = trim((string) $request->input('name'));

        if ($id <= 0 || $name === '') {
            $this->flash('Bitte alle Pflichtfelder ausfüllen.', 'error');

            return Response::redirect('/admin/document-catalog');
        }

        try {
            $this->catalog->updateMetadata(
                $id,
                $name,
                trim((string) $request->input('description', '')) ?: null,
                trim((string) $request->input('document_type', 'Unbekannt')) ?: 'Unbekannt',
                ((int) $request->input('category_id')) ?: null,
                $this->userId()
            );
            $this->flash('Vorlage aktualisiert.');
        } catch (\Throwable $e) {
            $this->flash('Aktualisierung fehlgeschlagen: ' . $e->getMessage(), 'error');
        }

        return Response::redirect('/admin/document-catalog');
    }

    /** Vorlage ersetzen: legt eine neue, unveränderliche Version an. */
    public function replace(Request $request): Response
    {
        $id = (int) $request->input('id');
        $file = $request->files()['template_file'] ?? null;

        if ($id <= 0 || !is_array($file)) {
            $this->flash('Bitte eine PDF-Datei auswählen.', 'error');

            return Response::redirect('/admin/document-catalog');
        }

        try {
            $version = $this->catalog->replaceTemplateFile($id, $file, $this->userId());
            $this->flash('Neue Version ' . $version . ' angelegt. Bereits verwendete Dokumente bleiben unverändert.');
        } catch (\Throwable $e) {
            $this->flash('Ersetzen fehlgeschlagen: ' . $e->getMessage(), 'error');
        }

        return Response::redirect('/admin/document-catalog');
    }

    /** Statusaktionen: aktivieren, deaktivieren, archivieren, wiederherstellen, löschen. */
    public function status(Request $request): Response
    {
        $id = (int) $request->input('id');
        $action = (string) $request->input('action');

        try {
            switch ($action) {
                case 'activate':
                    $this->catalog->setActive($id, true, $this->userId());
                    $this->flash('Vorlage aktiviert.');
                    break;
                case 'deactivate':
                    $this->catalog->setActive($id, false, $this->userId());
                    $this->flash('Vorlage deaktiviert.');
                    break;
                case 'archive':
                    $this->catalog->setArchived($id, true, $this->userId());
                    $this->flash('Vorlage archiviert.');
                    break;
                case 'restore':
                    $this->catalog->setArchived($id, false, $this->userId());
                    $this->flash('Vorlage wiederhergestellt.');
                    break;
                case 'delete':
                    if ($this->catalog->deleteTemplate($id, $this->userId())) {
                        $this->flash('Vorlage gelöscht.');
                    } else {
                        $this->flash('Vorlage wird von Patientenmappen verwendet und kann nur archiviert werden.', 'error');
                    }
                    break;
                default:
                    $this->flash('Unbekannte Aktion.', 'error');
            }
        } catch (\Throwable $e) {
            $this->flash('Aktion fehlgeschlagen: ' . $e->getMessage(), 'error');
        }

        return Response::redirect('/admin/document-catalog');
    }

    /** Kategorien verwalten (anlegen, umbenennen, löschen). */
    public function saveCategory(Request $request): Response
    {
        try {
            $message = $this->catalog->saveCategory(
                (string) $request->input('action', 'create'),
                (int) $request->input('id'),
                trim((string) $request->input('name')),
                $this->userId()
            );
            $this->flash($message);
        } catch (\Throwable $e) {
            $this->flash($e->getMessage(), 'error');
        }

        return Response::redirect('/admin/document-catalog');
    }

    /** Vorschau/Download einer Vorlagenversion (nur Admin/Dokumentenmanagement). */
    public function adminFile(Request $request): Response
    {
        $template = $this->templates->findById((int) $request->input('id'));
        if ($template === null) {
            return new Response('Nicht gefunden', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $versionNumber = (int) $request->input('version');
        $version = null;
        foreach ($this->templates->versions((int) $template['id']) as $candidate) {
            if ($versionNumber <= 0 && (int) $candidate['version'] === (int) $template['current_version']) {
                $version = $candidate;
                break;
            }
            if ($versionNumber > 0 && (int) $candidate['version'] === $versionNumber) {
                $version = $candidate;
                break;
            }
        }

        return $this->streamPdf(
            $version !== null ? (string) $version['file_path'] : '',
            (string) ($version['file_name'] ?? 'vorlage.pdf'),
            (string) $request->input('mode', 'preview') === 'download'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Personal-Dashboard                                                  */
    /* ------------------------------------------------------------------ */

    /** Aktive Vorlagen für die Auswahl (Suche + Kategoriefilter). */
    public function list(Request $request): Response
    {
        return $this->json([
            'templates' => $this->templates->activeForSelection(
                trim((string) $request->input('q', '')),
                (int) $request->input('category_id', 0)
            ),
            'categories' => $this->templates->categories(true),
        ]);
    }

    /** Vorschau der aktuellen Vorlagenversion für das Personal. */
    public function preview(Request $request): Response
    {
        $template = $this->templates->findById((int) $request->input('id'));
        if ($template === null || (int) $template['is_active'] !== 1 || (int) $template['is_archived'] === 1) {
            return new Response('Nicht gefunden', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $version = $this->templates->currentVersion((int) $template['id']);

        return $this->streamPdf($version !== null ? (string) $version['file_path'] : '', 'vorschau.pdf', false);
    }

    /** Vorlagen personalisiert einer Patientenmappe hinzufügen. */
    public function addToFolder(Request $request): Response
    {
        $caseNumber = trim((string) $request->input('case_number'));
        $templateIds = array_values(array_filter(array_map('intval', (array) $request->input('template_ids', []))));

        if ($caseNumber === '' || $templateIds === []) {
            return $this->json(['error' => 'Bitte mindestens ein Dokument auswählen.'], 422);
        }

        try {
            $created = $this->catalog->addToFolder(
                $caseNumber,
                $templateIds,
                $this->userId(),
                (string) ($_SESSION['auth_user']['username'] ?? '')
            );
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }

        return $this->json([
            'message' => count($created) . ' Dokument(e) zur Patientenmappe hinzugefügt.',
            'documents' => $created,
        ]);
    }

    /** Dokument aus einer Mappe entfernen (nur nicht unterschriebene Dokumente). */
    public function removeFromFolder(Request $request): Response
    {
        try {
            $name = $this->catalog->removeFromFolder((int) $request->input('document_id'), $this->userId());
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }

        return $this->json(['message' => $name . ' wurde aus der Mappe entfernt.']);
    }

    /** Dokumentreihenfolge einer Mappe ändern (bestimmt die Patientenansicht). */
    public function reorderFolder(Request $request): Response
    {
        $caseNumber = trim((string) $request->input('case_number'));
        $order = array_values(array_filter(array_map('intval', (array) $request->input('order', []))));

        if ($caseNumber === '' || $order === []) {
            return $this->json(['error' => 'Ungültige Reihenfolge.'], 422);
        }

        try {
            $this->catalog->reorderFolder($caseNumber, $order, $this->userId());
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }

        return $this->json(['message' => 'Reihenfolge gespeichert.']);
    }

    /* ------------------------------------------------------------------ */
    /* Interna                                                             */
    /* ------------------------------------------------------------------ */

    private function streamPdf(string $path, string $name, bool $download): Response
    {
        if ($path === '' || !is_file($path)) {
            return new Response('Datei nicht verfügbar', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($download ? 'attachment' : 'inline') . '; filename="' . str_replace('"', '', $name) . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function userId(): ?int
    {
        return isset($_SESSION['auth_user']['id']) ? (int) $_SESSION['auth_user']['id'] : null;
    }

    private function flash(string $message, string $type = 'success'): void
    {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    }

    /** @return array{message:string,type:string}|null */
    private function pullFlash(): ?array
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return is_array($flash) ? $flash : null;
    }
}
