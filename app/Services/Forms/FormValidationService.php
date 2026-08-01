<?php

declare(strict_types=1);

namespace App\Services\Forms;

/**
 * Serverseitige Formularvalidierung: einzige verbindliche Instanz für
 * Pflichtfelder und Formatregeln. Clientseitige Prüfungen dienen nur der
 * Anzeige – jede Eingabe wird hier erneut geprüft.
 */
final class FormValidationService
{
    public function __construct(private readonly FieldTypeRegistry $registry) {}

    /**
     * Validiert eine Wertemenge gegen die Felddefinitionen.
     *
     * @param array<int,array<string,mixed>> $fields  Felddefinitionen (form_fields-Zeilen)
     * @param array<string,string|null>      $values  field_uuid => Wert
     * @return array{valid:bool,errors:array<string,string>,missing_required:list<string>,filled_required:int,required_total:int}
     */
    public function validateAll(array $fields, array $values): array
    {
        $errors = [];
        $missingRequired = [];
        $requiredTotal = 0;
        $filledRequired = 0;

        foreach ($fields as $field) {
            $uuid = (string) $field['field_uuid'];
            $value = $values[$uuid] ?? null;
            $required = filter_var($field['required'] ?? false, FILTER_VALIDATE_BOOL);
            if ($required) {
                $requiredTotal++;
            }

            $error = $this->registry->validate($field, $value);
            if ($error !== null) {
                $errors[$uuid] = $error;
                if ($required && ($value === null || trim((string) $value) === '')) {
                    $missingRequired[] = $uuid;
                }
                continue;
            }

            if ($required && $value !== null && trim((string) $value) !== '') {
                $filledRequired++;
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'missing_required' => $missingRequired,
            'filled_required' => $filledRequired,
            'required_total' => $requiredTotal,
        ];
    }

    /** Validiert ein einzelnes Feld. @param array<string,mixed> $field */
    public function validateField(array $field, ?string $value): ?string
    {
        return $this->registry->validate($field, $value);
    }
}
