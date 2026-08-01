<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class DocumentAnalysisService
{
    public function __construct(
        private readonly LocalAiClient $visionClient,
        private readonly LocalAiClient $analysisClient,
        private readonly PromptService $promptService,
        private readonly CaseNumberExtractor $caseNumberExtractor
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
        $analysisPrompt = $this->promptService->getActivePrompt('analysis');
        $analysisModel = $_ENV['ANALYSIS_MODEL'] ?? 'gemma-4-e4b';
        $analysisMessages = [[
            'role' => 'system',
            'content' => $analysisPrompt,
        ], [
            'role' => 'user',
            'content' => $extractedText,
        ]];

        try {
            $analysisResponse = $this->analysisClient->chat([
                'model' => $analysisModel,
                'response_format' => ['type' => 'json_object'],
                'messages' => $analysisMessages,
            ]);
        } catch (RuntimeException $e) {
            // Manche lokalen Endpunkte (z. B. LM Studio) lehnen "response_format"
            // mit HTTP 4xx ab – dann ohne strukturierten Modus erneut versuchen.
            if ($e->getCode() < 400 || $e->getCode() >= 500) {
                throw $e;
            }
            $analysisResponse = $this->analysisClient->chat([
                'model' => $analysisModel,
                'messages' => $analysisMessages,
            ]);
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
