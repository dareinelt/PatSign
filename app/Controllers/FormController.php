<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\DocumentRepository;
use App\Services\DeviceService;
use App\Services\Forms\FormResponseService;

/**
 * REST-Endpunkte der Formularfunktion für Patientenmodus und Kioskmodus.
 *
 * Autorisierung:
 * - Patientenmodus: Dokument muss zur Fallnummer der aktiven Patientensitzung gehören.
 * - Kioskmodus: Geräteauthentifizierung; nur Dokumente der aktuell
 *   zugewiesenen Patientenmappe sind erreichbar.
 *
 * Alle Validierungen erfolgen ausschließlich serverseitig.
 */
final class FormController extends BaseController
{
    public function __construct(
        View $view,
        private readonly DocumentRepository $documents,
        private readonly FormResponseService $forms,
        private readonly DeviceService $devices
    ) {
        parent::__construct($view);
    }

    /* ---------------------------------------------------------- Patientenmodus */

    public function patientStructure(Request $request): Response
    {
        return $this->handle($this->patientDocument($request), fn (array $doc): array => $this->forms->structure($doc));
    }

    public function patientSave(Request $request): Response
    {
        return $this->handle($this->patientDocument($request), fn (array $doc): array => $this->forms->saveValues($doc, $this->valuesFromRequest($request)));
    }

    public function patientValidate(Request $request): Response
    {
        return $this->handle($this->patientDocument($request), fn (array $doc): array => $this->forms->validate($doc));
    }

    public function patientComplete(Request $request): Response
    {
        return $this->handle($this->patientDocument($request), fn (array $doc): array => $this->forms->complete($doc));
    }

    /* ------------------------------------------------------------- Kioskmodus */

    public function kioskStructure(Request $request): Response
    {
        return $this->handle($this->kioskDocument($request), fn (array $doc): array => $this->forms->structure($doc));
    }

    public function kioskSave(Request $request): Response
    {
        return $this->handle($this->kioskDocument($request), fn (array $doc): array => $this->forms->saveValues($doc, $this->valuesFromRequest($request)));
    }

    public function kioskValidate(Request $request): Response
    {
        return $this->handle($this->kioskDocument($request), fn (array $doc): array => $this->forms->validate($doc));
    }

    public function kioskComplete(Request $request): Response
    {
        return $this->handle($this->kioskDocument($request), fn (array $doc): array => $this->forms->complete($doc));
    }

    /* ----------------------------------------------------------------- intern */

    /** @param callable(array<string,mixed>):array<string,mixed> $action */
    private function handle(?array $document, callable $action): Response
    {
        if ($document === null) {
            return $this->json(['error' => 'Zugriff verweigert'], 403);
        }

        try {
            return $this->json($action($document));
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        } catch (\Throwable) {
            return $this->json(['error' => 'Formularaktion fehlgeschlagen.'], 500);
        }
    }

    /** Dokument der aktiven Patientensitzung (oder null). @return array<string,mixed>|null */
    private function patientDocument(Request $request): ?array
    {
        $session = $_SESSION['patient_session'] ?? null;
        if (!is_array($session)) {
            return null;
        }

        $document = $this->documents->findById((int) $request->input('document_id'));
        if ($document === null || (string) $document['case_number'] !== (string) ($session['case_number'] ?? '')) {
            return null;
        }

        return $document;
    }

    /** Dokument der aktuell zugewiesenen Kiosk-Patientenmappe (oder null). @return array<string,mixed>|null */
    private function kioskDocument(Request $request): ?array
    {
        $device = $this->devices->authenticate($request);
        if ($device === null) {
            return null;
        }

        return $this->devices->authorizedDocument($device, (int) $request->input('document_id'));
    }

    /**
     * Werte aus dem Request: JSON-Objekt im Feld "values" (field_uuid => Wert).
     *
     * @return array<string,string|null>
     */
    private function valuesFromRequest(Request $request): array
    {
        $raw = $request->input('values');
        $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        if (!is_array($decoded)) {
            return [];
        }

        $values = [];
        foreach ($decoded as $uuid => $value) {
            if (!is_string($uuid) || preg_match('/^[0-9a-f\-]{36}$/', $uuid) !== 1) {
                continue;
            }
            $values[$uuid] = $value === null ? null : (is_scalar($value) ? (string) $value : null);
        }

        return $values;
    }
}
