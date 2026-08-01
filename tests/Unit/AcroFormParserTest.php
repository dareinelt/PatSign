<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Forms\AcroFormParser;
use PHPUnit\Framework\TestCase;

final class AcroFormParserTest extends TestCase
{
    private string $pdfPath = '';

    protected function tearDown(): void
    {
        if ($this->pdfPath !== '' && is_file($this->pdfPath)) {
            @unlink($this->pdfPath);
        }
    }

    private function writePdf(string $content): string
    {
        $this->pdfPath = tempnam(sys_get_temp_dir(), 'patsign_test_') . '.pdf';
        file_put_contents($this->pdfPath, $content);

        return $this->pdfPath;
    }

    private function samplePdf(): string
    {
        return "%PDF-1.4\n"
            . "1 0 obj\n<< /Type /Catalog /Pages 2 0 R /AcroForm << /Fields [4 0 R 5 0 R 6 0 R] >> >>\nendobj\n"
            . "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 612 792] >>\nendobj\n"
            . "3 0 obj\n<< /Type /Page /Parent 2 0 R /Annots [4 0 R 5 0 R 6 0 R] >>\nendobj\n"
            . "4 0 obj\n<< /Type /Annot /Subtype /Widget /FT /Tx /T (Vorname) /TU (Ihr Vorname) /Ff 2 /Rect [61.2 712.8 306 736.56] >>\nendobj\n"
            . "5 0 obj\n<< /Type /Annot /Subtype /Widget /FT /Btn /T (Einwilligung) /Rect [61.2 653.4 85 677] >>\nendobj\n"
            . "6 0 obj\n<< /Type /Annot /Subtype /Widget /FT /Ch /Ff 131072 /T (Anrede) /Opt [(Frau) (Herr)] /Rect [320 712.8 500 736.56] >>\nendobj\n"
            . "%%EOF\n";
    }

    public function testReturnsEmptyListForMissingFile(): void
    {
        self::assertSame([], (new AcroFormParser())->parse('/nicht/vorhanden.pdf'));
    }

    public function testReturnsEmptyListForPdfWithoutAcroForm(): void
    {
        $path = $this->writePdf("%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");

        self::assertSame([], (new AcroFormParser())->parse($path));
    }

    public function testParsesWidgetAnnotations(): void
    {
        $fields = (new AcroFormParser())->parse($this->writePdf($this->samplePdf()));

        self::assertCount(3, $fields);

        $text = $fields[0];
        self::assertSame('Vorname', $text['name']);
        self::assertSame('text', $text['type']);
        self::assertSame('Ihr Vorname', $text['label']);
        self::assertSame(1, $text['page']);
        self::assertTrue($text['required']); // /Ff 2 = Pflichtfeld

        // Relative Koordinaten (0–1, Ursprung oben links): x = 61.2/612 = 0.1
        self::assertEqualsWithDelta(0.1, $text['x'], 0.0001);
        self::assertEqualsWithDelta((792 - 736.56) / 792, $text['y'], 0.0001);
        self::assertEqualsWithDelta((306 - 61.2) / 612, $text['width'], 0.0001);
        self::assertEqualsWithDelta((736.56 - 712.8) / 792, $text['height'], 0.0001);

        $checkbox = $fields[1];
        self::assertSame('checkbox', $checkbox['type']);
        self::assertFalse($checkbox['required']);

        $dropdown = $fields[2];
        self::assertSame('dropdown', $dropdown['type']); // /Ff 131072 = Combo
        self::assertSame(['Frau', 'Herr'], $dropdown['options']);
    }

    public function testSkipsPushButtons(): void
    {
        $pdf = "%PDF-1.4\n"
            . "1 0 obj\n<< /Type /Catalog /Pages 2 0 R /AcroForm << /Fields [4 0 R] >> >>\nendobj\n"
            . "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
            . "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Annots [4 0 R] >>\nendobj\n"
            . "4 0 obj\n<< /Type /Annot /Subtype /Widget /FT /Btn /Ff 65536 /T (Drucken) /Rect [10 10 60 30] >>\nendobj\n"
            . "%%EOF\n";

        self::assertSame([], (new AcroFormParser())->parse($this->writePdf($pdf)));
    }

    public function testMultilineTextBecomesTextarea(): void
    {
        $pdf = "%PDF-1.4\n"
            . "1 0 obj\n<< /Type /Catalog /Pages 2 0 R /AcroForm << /Fields [4 0 R] >> >>\nendobj\n"
            . "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
            . "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Annots [4 0 R] >>\nendobj\n"
            . "4 0 obj\n<< /Type /Annot /Subtype /Widget /FT /Tx /Ff 4096 /T (Beschwerden) /Rect [50 500 550 600] >>\nendobj\n"
            . "%%EOF\n";

        $fields = (new AcroFormParser())->parse($this->writePdf($pdf));

        self::assertCount(1, $fields);
        self::assertSame('textarea', $fields[0]['type']);
    }
}
