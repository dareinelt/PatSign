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
        $analysisResponse = $this->analysisClient->chat([
            'model' => $_ENV['ANALYSIS_MODEL'] ?? 'gemma-4-e4b',
            'response_format' => ['type' => 'json_object'],
            'messages' => [[
                'role' => 'system',
                'content' => $analysisPrompt,
            ], [
                'role' => 'user',
                'content' => $extractedText,
            ]],
        ]);

        $json = (string) ($analysisResponse['choices'][0]['message']['content'] ?? '{}');
        $structured = json_decode($json, true);
        if (!is_array($structured)) {
            throw new RuntimeException('Analysemodell lieferte kein gültiges JSON.');
        }

        $caseNumber = $this->caseNumberExtractor->extract($extractedText);
        $structured['case_number'] = $caseNumber['case_number'];
        $structured['case_number_confidence'] = $caseNumber['confidence'];

        return $structured;
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
