<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\DocumentTypeRepository;
use RuntimeException;

final class DocumentAnalysisService
{
    public function __construct(
        private readonly LocalAiClient $visionClient,
        private readonly LocalAiClient $analysisClient,
        private readonly PromptService $promptService,
        private readonly CaseNumberExtractor $caseNumberExtractor,
        private readonly DocumentTypeRepository $documentTypes
    ) {}

    /** @return array<string,mixed> */
    public function analyze(string $pdfPath): array
    {
        if (!is_file($pdfPath)) {
            throw new RuntimeException('PDF nicht gefunden.');
        }

        $images = $this->renderPdfPagesToImages($pdfPath);
        $visionPrompt = $this->promptService->getActivePrompt('vision');

        $visionResponse = $this->visionClient->chat([
            'model' => $_ENV['VISION_MODEL'] ?? 'vision-local',
            'messages' => [[
                'role' => 'user',
                'content' => array_merge([
                    ['type' => 'text', 'text' => $visionPrompt],
                ], array_map(
                    static fn (string $file): array => [
                        'type' => 'image_url',
                        'image_url' => ['url' => 'data:image/png;base64,' . base64_encode((string) file_get_contents($file))],
                    ],
                    $images
                )),
            ]],
        ]);

        $extractedText = (string) ($visionResponse['choices'][0]['message']['content'] ?? '');
        $analysisModel = $_ENV['ANALYSIS_MODEL'] ?? 'gemma-4-e4b';
        $analysisMessages = [[
            'role' => 'system',
            'content' => $this->buildAnalysisSystemPrompt(),
        ], [
            'role' => 'user',
            'content' => $extractedText,
        ]];

        $analysisRequest = [
            'model' => $analysisModel,
            'messages' => $analysisMessages,
            // Deterministische, knappe Antworten ohne Kreativspielraum:
            'temperature' => 0,
            // Das JSON-Objekt braucht nur wenige Token – begrenzt zusätzlich
            // ausschweifende Reasoning-/Prosa-Ausgaben lokaler Modelle.
            'max_tokens' => 256,
        ];

        try {
            $analysisResponse = $this->analysisClient->chat($analysisRequest + [
                'response_format' => ['type' => 'json_object'],
            ]);
        } catch (RuntimeException $e) {
            // Manche lokalen Endpunkte (z. B. LM Studio) lehnen "response_format"
            // mit HTTP 4xx ab – dann ohne strukturierten Modus erneut versuchen.
            if ($e->getCode() < 400 || $e->getCode() >= 500) {
                throw $e;
            }
            $analysisResponse = $this->analysisClient->chat($analysisRequest);
        }

        $content = (string) ($analysisResponse['choices'][0]['message']['content'] ?? '{}');
        $structured = $this->decodeJsonResponse($content);
        if (!is_array($structured)) {
            throw new RuntimeException('Analysemodell lieferte kein gültiges JSON.');
        }

        $caseNumber = $this->caseNumberExtractor->extract($extractedText);
        $structured['case_number'] = $caseNumber['case_number'];
        $structured['case_number_confidence'] = $caseNumber['confidence'];

        return $structured;
    }

    /**
     * Baut den System-Prompt für das Analysemodell: konfigurierter Basis-Prompt
     * plus harte Ausgaberegeln und die Whitelist der konfigurierten
     * Dokumententypen, an die sich das Modell halten muss.
     */
    private function buildAnalysisSystemPrompt(): string
    {
        $basePrompt = trim($this->promptService->getActivePrompt('analysis'));

        $typeNames = array_values(array_filter(array_map(
            static fn (array $row): string => trim((string) ($row['name'] ?? '')),
            $this->documentTypes->all()
        )));

        $rules = [
            'AUSGABEREGELN (zwingend):',
            '- Antworte mit genau einem JSON-Objekt und sonst nichts: kein Markdown, keine Code-Zäune, keine Erklärungen, keine Gedankengänge.',
            '- Verwende exakt diese Schlüssel: document_type, case_number, last_name, first_name, birth_date.',
            '- Wenn eine Information nicht eindeutig im Text steht, setze den Wert auf null. Erfinde nichts.',
            '- birth_date im Format TT.MM.JJJJ.',
        ];

        if ($typeNames !== []) {
            $rules[] = '- document_type MUSS exakt einer der folgenden Werte sein (keine eigenen Bezeichnungen, keine Kombinationen, keine Zusätze): '
                . implode(', ', array_map(static fn (string $n): string => '"' . $n . '"', $typeNames)) . '.';
            $fallback = in_array('Unbekannt', $typeNames, true) ? 'Unbekannt' : $typeNames[count($typeNames) - 1];
            $rules[] = '- Passt keiner der Werte eindeutig, verwende "' . $fallback . '".';
        }

        return $basePrompt . "\n\n" . implode("\n", $rules);
    }

    /**
     * Dekodiert die Modellantwort als JSON. Toleriert Markdown-Zäune
     * (```json …```) und Prosa vor/nach dem eigentlichen JSON-Objekt.
     */
    private function decodeJsonResponse(string $content): mixed
    {
        $decoded = json_decode(trim($content), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $m) === 1) {
            $decoded = json_decode($m[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            return json_decode(substr($content, $start, $end - $start + 1), true);
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
        $imagick->setResolution(300, 300);
        $imagick->readImage($pdfPath);

        $output = [];
        $index = 1;
        foreach ($imagick as $page) {
            $page->setImageFormat('png');
            $target = sys_get_temp_dir() . '/patsign_page_' . uniqid((string) $index, true) . '.png';
            $page->writeImage($target);
            $output[] = $target;
            $index++;
        }

        return $output;
    }
}
