<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Repositories\AuditLogRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\FormFieldRepository;
use App\Repositories\FormResponseRepository;
use App\Repositories\FormTemplateRepository;
use App\Services\SettingsService;
use RuntimeException;

/**
 * Verwaltet Formulareingaben: Struktur mit Vorbelegung ausliefern,
 * Eingaben automatisch zwischenspeichern (Autosave/Wiederaufnahme),
 * serverseitig validieren und abschließen.
 *
 * Felddefinitionen und Koordinaten stammen ausschließlich aus der Datenbank
 * und sind clientseitig nicht manipulierbar; nach der Unterschrift sind
 * Eingaben eingefroren.
 */
final class FormResponseService
{
    public function __construct(
        private readonly FormTemplateRepository $templates,
        private readonly FormFieldRepository $fields,
        private readonly FormResponseRepository $responses,
        private readonly FormValidationService $validation,
        private readonly DocumentRepository $documents,
        private readonly AuditLogRepository $auditLogs,
        private readonly SettingsService $settings
    ) {}

    /** Prüft, ob für das Dokument ein einsatzbereites Formular existiert. */
    public function hasReadyForm(int $documentId): bool
    {
        return $this->templates->findLatestReadyByDocument($documentId) !== null;
    }

    /**
     * Formularstruktur inkl. gespeicherter Eingaben und Vorbelegung.
     *
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    public function structure(array $document): array
    {
        $documentId = (int) $document['id'];
        $template = $this->templates->findLatestReadyByDocument($documentId);
        if ($template === null) {
            throw new RuntimeException('Für dieses Dokument liegt kein Formular vor.');
        }

        $templateId = (int) $template['id'];
        $fields = $this->fields->findByTemplate($templateId);
        $response = $this->responses->findOrCreate($templateId, $documentId, isset($document['case_number']) ? (string) $document['case_number'] : null);
        $values = $this->responses->values((int) $response['id']);
        $prefill = $this->prefillValues($document);

        $outFields = [];
        foreach ($fields as $field) {
            $fieldId = (int) $field['id'];
            $value = $values[$fieldId] ?? null;
            $prefilled = false;

            $prefillKey = (string) ($field['prefill_key'] ?? '');
            if ($value === null && $prefillKey !== '' && isset($prefill[$prefillKey]) && $prefill[$prefillKey] !== '') {
                $value = $prefill[$prefillKey];
                $prefilled = true;
                // Vorbelegung sofort persistieren, damit sie beim Abschluss vorliegt.
                $this->responses->saveValue((int) $response['id'], $fieldId, $value, true);
            }

            $outFields[] = [
                'uuid' => (string) $field['field_uuid'],
                'type' => (string) $field['type'],
                'label' => $field['label'] !== null ? (string) $field['label'] : null,
                'page' => (int) $field['page'],
                'x' => (float) $field['x'],
                'y' => (float) $field['y'],
                'width' => (float) $field['width'],
                'height' => (float) $field['height'],
                'required' => (bool) $field['required'],
                'options' => FieldTypeRegistry::options($field),
                'validation' => FieldTypeRegistry::rules($field),
                'value' => $value,
                'prefilled' => $prefilled,
                'locked' => $prefilled && (bool) $field['prefill_locked'],
            ];
        }

        $requiredTotal = count(array_filter($outFields, static fn (array $f): bool => $f['required']));

        return [
            'document_id' => $documentId,
            'template' => [
                'uuid' => (string) $template['template_uuid'],
                'version' => (int) $template['version'],
                'source' => (string) $template['source'],
            ],
            'status' => (string) $response['status'],
            'config' => [
                'autosave_interval' => max(1, $this->settings->getInt('forms.autosave_interval', 3)),
                'allow_handwriting' => $this->settings->getBool('forms.allow_handwriting', true),
                'allow_keyboard' => $this->settings->getBool('forms.allow_keyboard', true),
                'required_check' => $this->settings->getBool('forms.required_check_enabled', true),
            ],
            'fields' => $outFields,
        ];
    }

    /**
     * Autosave: speichert Eingaben (field_uuid => Wert) und liefert Feldfehler.
     *
     * @param array<string,mixed> $document
     * @param array<string,string|null> $values
     * @return array{errors:array<string,string>,filled_required:int,required_total:int,complete:bool}
     */
    public function saveValues(array $document, array $values): array
    {
        [$template, $fields, $response] = $this->context($document);
        $this->assertNotSigned($response);

        $byUuid = $this->fieldsByUuid($fields);
        $errors = [];
        foreach ($values as $uuid => $value) {
            $field = $byUuid[$uuid] ?? null;
            if ($field === null) {
                continue; // Unbekannte Felder werden ignoriert (keine Manipulation möglich).
            }
            $value = $value !== null ? $this->sanitize((string) $value) : null;
            $error = $this->validation->validateField($field, $value);
            $errors_uuid = $error !== null && $value !== null && trim($value) !== '' ? $error : null;
            if ($errors_uuid !== null) {
                $errors[$uuid] = $errors_uuid;
            }
            $this->responses->saveValue((int) $response['id'], (int) $field['id'], $value, $errors_uuid === null);
        }

        // Zwischengespeicherte Eingaben ändern den Abschlussstatus ggf. zurück.
        $this->responses->reopen((int) $response['id']);
        $state = $this->validationState($fields, (int) $response['id']);
        $this->updateDocumentFormStatus((int) $document['id'], $state);

        $this->audit('form_autosave', [
            'template_id' => (int) $template['id'],
            'saved_fields' => count($values),
            'validation_errors' => count($errors),
        ], (int) $document['id']);
        if ($errors !== []) {
            $this->audit('form_validation_failed', ['fields' => array_keys($errors)], (int) $document['id']);
        }

        return [
            'errors' => $errors,
            'filled_required' => $state['filled_required'],
            'required_total' => $state['required_total'],
            'complete' => $state['valid'],
        ];
    }

    /**
     * Vollständige serverseitige Validierung des aktuellen Stands.
     *
     * @param array<string,mixed> $document
     * @return array{valid:bool,errors:array<string,string>,missing_required:list<string>,filled_required:int,required_total:int}
     */
    public function validate(array $document): array
    {
        [, $fields, $response] = $this->context($document);

        return $this->validationState($fields, (int) $response['id']);
    }

    /**
     * Schließt das Formular ab (alle Pflichtfelder müssen gültig sein).
     *
     * @param array<string,mixed> $document
     * @return array{valid:bool,errors:array<string,string>,missing_required:list<string>,filled_required:int,required_total:int}
     */
    public function complete(array $document): array
    {
        [$template, $fields, $response] = $this->context($document);
        $this->assertNotSigned($response);

        $state = $this->validationState($fields, (int) $response['id']);
        $enforce = $this->settings->getBool('forms.required_check_enabled', true);
        if ($enforce && !$state['valid']) {
            $this->audit('form_validation_failed', ['fields' => array_keys($state['errors'])], (int) $document['id']);

            return $state;
        }

        $this->responses->markCompleted((int) $response['id']);
        $this->documents->updateFormStatus((int) $document['id'], 'complete');
        $this->audit('form_completed', [
            'template_id' => (int) $template['id'],
            'filled_required' => $state['filled_required'],
            'required_total' => $state['required_total'],
        ], (int) $document['id']);

        return ['valid' => true] + $state;
    }

    /**
     * Werte für die PDF-Erzeugung: field-Zeilen inkl. "value".
     *
     * @param array<string,mixed> $document
     * @return array{template:array<string,mixed>,response:array<string,mixed>,fields:array<int,array<string,mixed>>}
     */
    public function resolvedFields(array $document): array
    {
        [$template, $fields, $response] = $this->context($document);
        $values = $this->responses->values((int) $response['id']);
        foreach ($fields as &$field) {
            $field['value'] = $values[(int) $field['id']] ?? null;
        }

        return ['template' => $template, 'response' => $response, 'fields' => $fields];
    }

    /** Friert die Antwort nach der Unterschrift ein. */
    public function markSigned(int $responseId, string $filledDocumentId, string $filledPdfPath): void
    {
        $this->responses->markSigned($responseId, $filledDocumentId, $filledPdfPath);
    }

    /** @param array<string,mixed> $document @return array{0:array<string,mixed>,1:array<int,array<string,mixed>>,2:array<string,mixed>} */
    private function context(array $document): array
    {
        $documentId = (int) $document['id'];
        $template = $this->templates->findLatestReadyByDocument($documentId);
        if ($template === null) {
            throw new RuntimeException('Für dieses Dokument liegt kein Formular vor.');
        }
        $fields = $this->fields->findByTemplate((int) $template['id']);
        $response = $this->responses->findOrCreate((int) $template['id'], $documentId, isset($document['case_number']) ? (string) $document['case_number'] : null);

        return [$template, $fields, $response];
    }

    /** @param array<string,mixed> $response */
    private function assertNotSigned(array $response): void
    {
        if (($response['status'] ?? '') === 'signed') {
            throw new RuntimeException('Das Formular wurde bereits unterschrieben und kann nicht mehr geändert werden.');
        }
    }

    /**
     * @param array<int,array<string,mixed>> $fields
     * @return array{valid:bool,errors:array<string,string>,missing_required:list<string>,filled_required:int,required_total:int}
     */
    private function validationState(array $fields, int $responseId): array
    {
        $values = $this->responses->values($responseId);
        $byUuid = [];
        foreach ($fields as $field) {
            $byUuid[(string) $field['field_uuid']] = $values[(int) $field['id']] ?? null;
        }

        return $this->validation->validateAll($fields, $byUuid);
    }

    /** @param array{valid:bool,filled_required:int,required_total:int} $state */
    private function updateDocumentFormStatus(int $documentId, array $state): void
    {
        $status = $state['valid'] ? 'complete' : ($state['filled_required'] > 0 ? 'partial' : 'analyzed');
        $this->documents->updateFormStatus($documentId, $status);
    }

    /** @param array<int,array<string,mixed>> $fields @return array<string,array<string,mixed>> */
    private function fieldsByUuid(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            $out[(string) $field['field_uuid']] = $field;
        }

        return $out;
    }

    /** Entfernt Steuerzeichen; HTML wird erst bei der Ausgabe maskiert. */
    private function sanitize(string $value): string
    {
        if (FieldTypeRegistry::isHandwritingValue($value)) {
            return $value;
        }

        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', mb_substr($value, 0, 10000));
    }

    /** @param array<string,mixed> $document @return array<string,string> */
    private function prefillValues(array $document): array
    {
        if (!$this->settings->getBool('forms.prefill_enabled', true)) {
            return [];
        }

        $birthDate = (string) ($document['birth_date'] ?? '');
        if ($birthDate !== '' && ($ts = strtotime($birthDate)) !== false) {
            $birthDate = date('d.m.Y', $ts);
        }

        return [
            'first_name' => (string) ($document['first_name'] ?? ''),
            'last_name' => (string) ($document['last_name'] ?? ''),
            'birth_date' => $birthDate,
            'case_number' => (string) ($document['case_number'] ?? ''),
            'current_date' => date('d.m.Y'),
        ];
    }

    /** @param array<string,mixed> $context */
    private function audit(string $event, array $context, int $documentId): void
    {
        try {
            $this->auditLogs->log($event, $context, null, $documentId);
        } catch (\Throwable) {
            // Audit-Logging darf die Eingabe nicht verhindern.
        }
    }
}
