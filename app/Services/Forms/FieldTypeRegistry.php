<?php

declare(strict_types=1);

namespace App\Services\Forms;

/**
 * Zentrale, erweiterbare Registrierung aller Feldtypen inklusive
 * serverseitiger Validierung. Neue Feldtypen können über register() ergänzt
 * werden, ohne bestehende Komponenten zu ändern.
 */
final class FieldTypeRegistry
{
    /** @var array<string,array{label:string,choice:bool,multi:bool,validator:callable(string,array<string,mixed>):?string}> */
    private array $types = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    /** @param callable(string,array<string,mixed>):?string $validator liefert Fehlermeldung oder null */
    public function register(string $name, string $label, callable $validator, bool $choice = false, bool $multi = false): void
    {
        $this->types[$name] = ['label' => $label, 'choice' => $choice, 'multi' => $multi, 'validator' => $validator];
    }

    public function has(string $name): bool
    {
        return isset($this->types[$name]);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->types);
    }

    public function isChoice(string $name): bool
    {
        return $this->types[$name]['choice'] ?? false;
    }

    /**
     * Validiert einen Wert gegen Feldtyp und Regeln (validation_json).
     * Liefert eine Fehlermeldung oder null bei gültigem Wert.
     *
     * @param array<string,mixed> $field Felddefinition (type, required, options/options_json, validation/validation_json)
     */
    public function validate(array $field, ?string $value): ?string
    {
        $type = (string) ($field['type'] ?? 'text');
        $required = filter_var($field['required'] ?? false, FILTER_VALIDATE_BOOL);
        $value = $value !== null ? trim($value) : '';

        if ($value === '' || ($type === 'checkbox' && $required && in_array($value, ['0', 'false'], true))) {
            return $required ? 'Dieses Feld ist ein Pflichtfeld.' : null;
        }

        if (!isset($this->types[$type])) {
            return 'Unbekannter Feldtyp.';
        }

        $error = ($this->types[$type]['validator'])($value, $field);
        if ($error !== null) {
            return $error;
        }

        return $this->applyGenericRules($value, self::rules($field));
    }

    public static function isHandwritingValue(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, 'data:image/png;base64,');
    }

    /** @param array<string,mixed> $field @return array<string,mixed> */
    public static function rules(array $field): array
    {
        $rules = $field['validation'] ?? ($field['validation_json'] ?? []);
        if (is_string($rules)) {
            $rules = json_decode($rules, true);
        }

        return is_array($rules) ? $rules : [];
    }

    /** @param array<string,mixed> $field @return list<string> */
    public static function options(array $field): array
    {
        $options = $field['options'] ?? ($field['options_json'] ?? []);
        if (is_string($options)) {
            $options = json_decode($options, true);
        }
        if (!is_array($options)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($o): string => is_array($o) ? (string) ($o['value'] ?? $o['label'] ?? '') : (string) $o,
            $options
        ), static fn (string $o): bool => $o !== ''));
    }

    /** @param array<string,mixed> $field @return list<string> Mehrfachauswahl-Werte */
    public static function multiValues(string $value): array
    {
        $parts = str_contains($value, "\u{1F}") ? explode("\u{1F}", $value) : explode(',', $value);

        return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
    }

    /** @param array<string,mixed> $rules */
    private function applyGenericRules(string $value, array $rules): ?string
    {
        if (self::isHandwritingValue($value)) {
            return null;
        }
        if (isset($rules['min_length']) && mb_strlen($value) < (int) $rules['min_length']) {
            return 'Bitte mindestens ' . (int) $rules['min_length'] . ' Zeichen eingeben.';
        }
        if (isset($rules['max_length']) && mb_strlen($value) > (int) $rules['max_length']) {
            return 'Bitte höchstens ' . (int) $rules['max_length'] . ' Zeichen eingeben.';
        }
        if (isset($rules['regex']) && is_string($rules['regex']) && $rules['regex'] !== '') {
            $pattern = '/' . str_replace('/', '\/', $rules['regex']) . '/u';
            if (@preg_match($pattern, $value) !== 1) {
                return 'Die Eingabe entspricht nicht dem geforderten Format.';
            }
        }

        return null;
    }

    private function registerDefaults(): void
    {
        $none = static fn (string $v, array $f): ?string => null;

        $this->register('text', 'Freitext', $none);
        $this->register('textarea', 'Mehrzeiliger Text', $none);

        $this->register('initials', 'Initialen', static function (string $v): ?string {
            if (self::isHandwritingValue($v)) {
                return null;
            }

            return mb_strlen($v) <= 6 ? null : 'Bitte höchstens 6 Zeichen eingeben.';
        });

        $this->register('number', 'Zahl', static function (string $v, array $f): ?string {
            $normalized = str_replace(',', '.', $v);
            if (!is_numeric($normalized)) {
                return 'Bitte eine gültige Zahl eingeben.';
            }
            $rules = self::rules($f);
            $number = (float) $normalized;
            if (isset($rules['min']) && $number < (float) $rules['min']) {
                return 'Der Wert muss mindestens ' . $rules['min'] . ' betragen.';
            }
            if (isset($rules['max']) && $number > (float) $rules['max']) {
                return 'Der Wert darf höchstens ' . $rules['max'] . ' betragen.';
            }

            return null;
        });

        $this->register('date', 'Datum', static function (string $v): ?string {
            foreach (['d.m.Y', 'Y-m-d'] as $format) {
                $dt = \DateTimeImmutable::createFromFormat('!' . $format, $v);
                if ($dt !== false && $dt->format($format) === $v) {
                    return null;
                }
            }

            return 'Bitte ein gültiges Datum im Format TT.MM.JJJJ eingeben.';
        });

        $this->register('time', 'Uhrzeit', static fn (string $v): ?string =>
            preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $v) === 1 ? null : 'Bitte eine gültige Uhrzeit im Format HH:MM eingeben.');

        $this->register('yesno', 'Ja/Nein', static fn (string $v): ?string =>
            in_array(mb_strtolower($v), ['ja', 'nein'], true) ? null : 'Bitte Ja oder Nein auswählen.', true);

        $this->register('checkbox', 'Checkbox', static fn (string $v): ?string =>
            in_array($v, ['0', '1', 'true', 'false'], true) ? null : 'Ungültiger Wert.', true);

        $choiceValidator = static function (string $v, array $f): ?string {
            $options = self::options($f);

            return $options === [] || in_array($v, $options, true) ? null : 'Bitte eine gültige Option auswählen.';
        };
        $this->register('radio', 'Radio Buttons', $choiceValidator, true);
        $this->register('dropdown', 'Dropdown', $choiceValidator, true);

        $this->register('multiselect', 'Mehrfachauswahl', static function (string $v, array $f): ?string {
            $options = self::options($f);
            foreach (self::multiValues($v) as $item) {
                if ($options !== [] && !in_array($item, $options, true)) {
                    return 'Bitte nur gültige Optionen auswählen.';
                }
            }

            return null;
        }, true, true);

        $this->register('signature', 'Unterschriftsfeld', static fn (string $v): ?string =>
            self::isHandwritingValue($v) ? null : 'Bitte unterschreiben Sie im Feld.');

        $this->register('phone', 'Telefonnummer', static fn (string $v): ?string =>
            preg_match('/^\+?[0-9 \/\-()]{6,25}$/', $v) === 1 ? null : 'Bitte eine gültige Telefonnummer eingeben.');

        $this->register('email', 'E-Mail-Adresse', static fn (string $v): ?string =>
            filter_var($v, FILTER_VALIDATE_EMAIL) !== false ? null : 'Bitte eine gültige E-Mail-Adresse eingeben.');
    }
}
