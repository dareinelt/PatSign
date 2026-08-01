<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Repositories\AuditLogRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\FormFieldRepository;
use App\Repositories\FormResponseRepository;
use App\Repositories\FormTemplateRepository;
use App\Services\LocalAiClient;
use App\Services\SettingsService;
use RuntimeException;

/**
 * Formularanalyse: erkennt sämtliche ausfüllbaren Bereiche eines Dokuments.
 *
 * Vorhandene PDF-Formularfelder (AcroForms) haben Vorrang; andernfalls
 * ermittelt die Vision-KI die Eingabefelder mit strukturierten Koordinaten.
 * Das Ergebnis wird als versionierte Formularvorlage gespeichert – die PDF
 * selbst bleibt unverändert.
 */
final class FormAnalysisService
{
    private const PREFILL_PATTERNS = [
        'first_name' => '/vorname/i',
        'last_name' => '/nachname|familienname/i',
        'birth_date' => '/geburtsdatum|geburtstag|geb\.\s*datum/i',
        'case_number' => '/fallnummer|fall\-?nr/i',
        'current_date' => '/^datum$|heutiges datum|aktuelles datum/i',
    ];

    public function __construct(
        private readonly AcroFormParser $acroFormParser,
        private readonly LocalAiClient $visionClient,
        private readonly FieldTypeRegistry $registry,
        private readonly FormTemplateRepository $templates,
        private readonly FormFieldRepository $fields,
        private readonly DocumentRepository $documents,
        private readonly AuditLogRepository $auditLogs,
        private readonly SettingsService $settings
    ) {}

    /**
     * Analysiert das Dokument und legt eine neue Formularvorlage an.
     * Liefert die Vorlagen-ID oder wirft eine Exception bei Fehlschlag.
     */
    public function analyzeDocument(int $documentId): int
    {
        $document = $this->documents->findById($documentId);
        if ($document === null) {
            throw new RuntimeException('Dokument nicht gefunden.');
        }

        $pdfPath = (string) $document['original_path'];
        $model = $this->settings->getString('forms.vision_model', (string) ($_ENV['VISION_MODEL'] ?? 'vision-local'));

        $templateId = $this->templates->create([
            'template_uuid' => FormResponseRepository::uuid(),
            'document_id' => $documentId,
            'version' => $this->templates->nextVersion($documentId),
            'source' => 'acroform',
            'status' => 'analyzing',
            'page_count' => 0,
            'field_count' => 0,
            'required_count' => 0,
            'analysis_model' => null,
            'error_message' => null,
        ]);

        try {
            // AcroForm-Felder haben Vorrang vor der Vision-Erkennung.
            $detected = $this->acroFormParser->parse($pdfPath);
            $source = 'acroform';
            if ($detected === []) {
                $detected = $this->detectWithVision($pdfPath, $model);
                $source = 'vision';
            }

            if ($detected === []) {
                throw new RuntimeException('Keine Formularfelder erkannt.');
            }

            $requiredCount = 0;
            $pageCount = 0;
            $sort = 0;
            foreach ($detected as $field) {
                $normalized = $this->normalizeField($field, $sort++);
                if ($normalized === null) {
                    continue;
                }
                $this->fields->create($normalized + ['template_id' => $templateId]);
                $requiredCount += (int) $normalized['required'];
                $pageCount = max($pageCount, (int) $normalized['page']);
            }

            $stored = $this->fields->findByTemplate($templateId);
            if ($stored === []) {
                throw new RuntimeException('Keine verwertbaren Formularfelder erkannt.');
            }

            $this->templates->updateCounts($templateId, $pageCount, count($stored), $requiredCount);
            $this->templates->updateMeta($templateId, $source, $source === 'vision' ? $model : null);
            $this->templates->updateStatus($templateId, 'ready');

            $this->audit('form_analysis_completed', [
                'template_id' => $templateId,
                'source' => $source,
                'field_count' => count($stored),
                'required_count' => $requiredCount,
                'pages_with_fields' => $pageCount,
            ], $documentId);

            return $templateId;
        } catch (\Throwable $e) {
            $this->templates->updateStatus($templateId, 'error', $e->getMessage());
            $this->audit('form_analysis_failed', ['template_id' => $templateId, 'error' => $e->getMessage()], $documentId);
            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Vision-Erkennung: liefert Felder mit relativen Koordinaten.
     *
     * @return array<int,array<string,mixed>>
     */
    private function detectWithVision(string $pdfPath, string $model): array
    {
        $images = $this->renderPdfPagesToImages($pdfPath);
        if ($images === []) {
            return [];
        }

        $prompt = $this->visionPrompt();
        $fields = [];

        try {
            foreach ($images as $index => $file) {
                $response = $this->visionClient->chat([
                    'model' => $model,
                    'temperature' => 0,
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $prompt . "\nDies ist Seite " . ($index + 1) . ' des Dokuments.'],
                            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . base64_encode((string) file_get_contents($file))]],
                        ],
                    ]],
                ]);

                $content = (string) ($response['choices'][0]['message']['content'] ?? '');
                foreach ($this->decodeFields($content) as $field) {
                    $field['page'] = $index + 1;
                    $fields[] = $field;
                }
            }
        } finally {
            foreach ($images as $file) {
                @unlink($file);
            }
        }

        return $fields;
    }

    private function visionPrompt(): string
    {
        $types = implode('", "', $this->registry->names());

        return <<<PROMPT
Du analysierst ein gescanntes Formular. Erkenne alle Bereiche, die ein Patient ausfüllen soll: Freitextfelder (Linien, leere Kästen), Checkboxen, Auswahlfelder, Tabellenzellen und Bereiche für handschriftliche Angaben.
Antworte mit genau einem JSON-Objekt und sonst nichts:
{"fields":[{"type":"text","label":"Vorname","x":12.0,"y":34.0,"width":28.0,"height":2.4,"required":true,"options":[]}]}
Regeln:
- "type" MUSS einer dieser Werte sein: "{$types}".
- x, y, width, height in Prozent der Seitenbreite/-höhe (0–100), Ursprung oben links; x/y bezeichnen die linke obere Ecke des Eingabebereichs.
- "label" ist die sichtbare Beschriftung neben dem Feld (oder null).
- "required" nur true, wenn das Feld erkennbar verpflichtend ist (z. B. mit * markiert).
- "options" nur für Auswahlfelder (radio, dropdown, multiselect, yesno) mit den sichtbaren Antwortmöglichkeiten.
- Erfinde keine Felder. Gibt es keine, antworte mit {"fields":[]}.
PROMPT;
    }

    /** @return array<int,array<string,mixed>> */
    private function decodeFields(string $content): array
    {
        $decoded = json_decode(trim($content), true);
        if (!is_array($decoded) && preg_match('/\{.*\}/s', $content, $m) === 1) {
            $decoded = json_decode($m[0], true);
        }
        $fields = is_array($decoded) ? ($decoded['fields'] ?? []) : [];
        if (!is_array($fields)) {
            return [];
        }

        // Prozentangaben (0–100) in relative Koordinaten (0–1) umrechnen.
        return array_map(static function (array $f): array {
            foreach (['x', 'y', 'width', 'height'] as $key) {
                $f[$key] = max(0.0, min(1.0, ((float) ($f[$key] ?? 0)) / 100));
            }

            return $f;
        }, array_values(array_filter($fields, 'is_array')));
    }

    /** @param array<string,mixed> $field @return array<string,mixed>|null */
    private function normalizeField(array $field, int $sort): ?array
    {
        $type = (string) ($field['type'] ?? 'text');
        if (!$this->registry->has($type)) {
            $type = 'text';
        }

        $width = (float) ($field['width'] ?? 0);
        $height = (float) ($field['height'] ?? 0);
        if ($width <= 0 || $height <= 0) {
            return null;
        }

        $label = isset($field['label']) && is_string($field['label']) ? trim($field['label']) : null;
        $name = trim((string) ($field['name'] ?? '')) ?: ($label ?? 'feld_' . ($sort + 1));
        $options = FieldTypeRegistry::options($field);
        if ($type === 'yesno' && $options === []) {
            $options = ['Ja', 'Nein'];
        }

        $prefillEnabled = $this->settings->getBool('forms.prefill_enabled', true);

        return [
            'field_uuid' => FormResponseRepository::uuid(),
            'name' => mb_substr($name, 0, 190),
            'type' => $type,
            'label' => $label !== null ? mb_substr($label, 0, 255) : null,
            'page' => max(1, (int) ($field['page'] ?? 1)),
            'x' => round(max(0.0, min(1.0, (float) ($field['x'] ?? 0))), 6),
            'y' => round(max(0.0, min(1.0, (float) ($field['y'] ?? 0))), 6),
            'width' => round(min(1.0, $width), 6),
            'height' => round(min(1.0, $height), 6),
            'required' => filter_var($field['required'] ?? false, FILTER_VALIDATE_BOOL) ? 1 : 0,
            'options_json' => $options !== [] ? json_encode($options, JSON_UNESCAPED_UNICODE) : null,
            'validation_json' => null,
            'prefill_key' => $prefillEnabled ? $this->inferPrefillKey($name . ' ' . (string) $label) : null,
            'prefill_locked' => $this->settings->getBool('forms.prefill_locked', false) ? 1 : 0,
            'sort_order' => $sort,
        ];
    }

    private function inferPrefillKey(string $haystack): ?string
    {
        foreach (self::PREFILL_PATTERNS as $key => $pattern) {
            if (preg_match($pattern, trim($haystack)) === 1) {
                return $key;
            }
        }

        return null;
    }

    /** @return array<int,string> */
    private function renderPdfPagesToImages(string $pdfPath): array
    {
        if (!extension_loaded('imagick')) {
            throw new RuntimeException('Imagick wird benötigt, um PDF-Seiten lokal als Bild zu rendern.');
        }

        $imagick = new \Imagick();
        $imagick->setResolution(200, 200);
        $imagick->readImage($pdfPath);

        $output = [];
        $index = 1;
        foreach ($imagick as $page) {
            $page->setImageFormat('png');
            $target = sys_get_temp_dir() . '/patsign_form_' . uniqid((string) $index, true) . '.png';
            $page->writeImage($target);
            $output[] = $target;
            $index++;
        }

        return $output;
    }

    /** @param array<string,mixed> $context */
    private function audit(string $event, array $context, int $documentId): void
    {
        try {
            $this->auditLogs->log($event, $context, null, $documentId);
        } catch (\Throwable) {
            // Audit-Logging darf die Analyse nicht verhindern.
        }
    }
}
