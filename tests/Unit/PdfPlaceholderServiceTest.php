<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Catalog\PdfPlaceholderService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use setasign\Fpdi\Fpdi;

final class PdfPlaceholderServiceTest extends TestCase
{
    private PdfPlaceholderService $service;
    private string $workDir;

    protected function setUp(): void
    {
        $this->service = new PdfPlaceholderService();
        $this->workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'patsign-catalog-test-' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->workDir);
    }

    /** Erzeugt eine Test-PDF mit Standard-Font (Helvetica) und Platzhaltern. */
    private function createTemplate(string ...$lines): string
    {
        $pdf = new Fpdi();
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);
        foreach ($lines as $line) {
            $pdf->Cell(0, 10, $line, 0, 1);
        }
        $path = $this->workDir . DIRECTORY_SEPARATOR . 'template.pdf';
        $pdf->Output('F', $path);

        return $path;
    }

    public function testValidateAcceptsValidPdf(): void
    {
        $path = $this->createTemplate('Einwilligung');
        $this->service->validate($path, 1024 * 1024);
        $this->assertFileExists($path);
    }

    public function testValidateRejectsNonPdf(): void
    {
        $path = $this->workDir . DIRECTORY_SEPARATOR . 'fake.pdf';
        file_put_contents($path, 'Das ist keine PDF-Datei');

        $this->expectException(RuntimeException::class);
        $this->service->validate($path, 1024 * 1024);
    }

    public function testValidateRejectsOversizedFile(): void
    {
        $path = $this->createTemplate('Einwilligung');

        $this->expectException(RuntimeException::class);
        $this->service->validate($path, 10);
    }

    public function testExtractPlaceholdersFindsAllMarkers(): void
    {
        $path = $this->createTemplate(
            'Fallnummer: {{CASE_NUMBER}}',
            'Patient: {{FIRST_NAME}} {{LAST_NAME}}',
            'Datum: {{CURRENT_DATE}}'
        );

        $found = $this->service->extractPlaceholders($path);

        sort($found);
        $this->assertSame(['CASE_NUMBER', 'CURRENT_DATE', 'FIRST_NAME', 'LAST_NAME'], $found);
    }

    public function testExtractPlaceholdersReturnsEmptyForPlainDocument(): void
    {
        $path = $this->createTemplate('Einwilligung ohne Platzhalter');

        $this->assertSame([], $this->service->extractPlaceholders($path));
    }

    public function testPersonalizeReplacesValuesAndFallback(): void
    {
        $path = $this->createTemplate(
            'Fallnummer: {{CASE_NUMBER}}',
            'Patient: {{FULL_NAME}}',
            'Station: {{WARD}}'
        );
        $output = $this->workDir . DIRECTORY_SEPARATOR . 'personalized.pdf';

        $result = $this->service->personalize($path, [
            'CASE_NUMBER' => '92612345',
            'FULL_NAME' => 'Erika Musterfrau',
        ], $output, '____________');

        $this->assertSame($output, $result['path']);
        $this->assertContains('CASE_NUMBER', $result['replaced']);
        $this->assertContains('FULL_NAME', $result['replaced']);

        $content = (string) file_get_contents($output);
        $this->assertStringStartsWith('%PDF-', $content);

        // Inhalte liegen in (rekomprimierten) Streams: zum Prüfen dekomprimieren
        $text = $content;
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $content, $m) > 0) {
            foreach ($m[1] as $stream) {
                $inflated = @gzuncompress($stream);
                $text .= $inflated !== false ? $inflated : $stream;
            }
        }
        $this->assertStringContainsString('92612345', $text);
        $this->assertStringContainsString('Erika Musterfrau', $text);
        $this->assertStringContainsString('____________', $text);
        $this->assertStringNotContainsString('{{CASE_NUMBER}}', $text);
        $this->assertStringNotContainsString('{{FULL_NAME}}', $text);
        $this->assertStringNotContainsString('{{WARD}}', $text);
        $this->assertSame([], $this->service->extractPlaceholders($output));

        // Ergebnis muss weiterhin eine gültige, von FPDI lesbare PDF sein
        $reader = new Fpdi();
        $this->assertSame(1, $reader->setSourceFile($output));
    }

    public function testPersonalizeKeepsRemainingPlaceholdersWithoutFallback(): void
    {
        $path = $this->createTemplate('Station: {{WARD}}');
        $output = $this->workDir . DIRECTORY_SEPARATOR . 'no-fallback.pdf';

        $result = $this->service->personalize($path, [], $output, '');

        $this->assertSame([], $result['replaced']);
        $this->assertFileExists($output);
    }
}
