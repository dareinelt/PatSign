<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Repositories\FormResponseRepository;
use setasign\Fpdi\Fpdi;

/**
 * Erzeugt serverseitig die ausgefüllte Version eines Formulars:
 * Alle Seiten des Original-PDFs werden importiert und die Formularinhalte
 * an den erkannten Koordinaten aufgedruckt. Das Original bleibt unverändert;
 * die ausgefüllte Version erhält eine eigene Dokument-ID.
 */
final class FilledPdfService
{
    public function __construct(private readonly string $processedPath) {}

    /**
     * @param array<string,mixed> $document Dokument-Datensatz
     * @param array<int,array<string,mixed>> $fields Felder inkl. "value"
     * @return array{path:string,filled_document_id:string}
     */
    public function render(array $document, array $fields): array
    {
        $originalPath = (string) ($document['original_path'] ?? '');
        $filledDocumentId = FormResponseRepository::uuid();

        if (!is_dir($this->processedPath)) {
            @mkdir($this->processedPath, 0775, true);
        }
        $outputPath = rtrim($this->processedPath, '/\\') . DIRECTORY_SEPARATOR . 'form_' . $filledDocumentId . '.pdf';

        $byPage = [];
        foreach ($fields as $field) {
            $byPage[(int) $field['page']][] = $field;
        }

        $pdf = new Fpdi();
        $pdf->SetAutoPageBreak(false);
        $pageCount = $pdf->setSourceFile($originalPath);

        for ($page = 1; $page <= $pageCount; $page++) {
            $template = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($template);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template);

            foreach ($byPage[$page] ?? [] as $field) {
                $this->drawField($pdf, $field, (float) $size['width'], (float) $size['height']);
            }
        }

        $pdf->Output('F', $outputPath);

        return ['path' => $outputPath, 'filled_document_id' => $filledDocumentId];
    }

    /**
     * Brennt Freihand-Stifteingaben (Freihandmodus) als transparente
     * PNG-Ebenen seitenfüllend in eine Kopie des Quell-PDFs ein.
     *
     * @param string $sourcePath Quell-PDF (Original oder ausgefüllte Version)
     * @param array<int,string> $pageImages Seitennummer => PNG-Data-URL
     * @return string Pfad der erzeugten PDF-Datei
     */
    public function renderInk(string $sourcePath, array $pageImages): string
    {
        if (!is_dir($this->processedPath)) {
            @mkdir($this->processedPath, 0775, true);
        }
        $outputPath = rtrim($this->processedPath, '/\\') . DIRECTORY_SEPARATOR . 'ink_' . FormResponseRepository::uuid() . '.pdf';

        $pdf = new Fpdi();
        $pdf->SetAutoPageBreak(false);
        $pageCount = $pdf->setSourceFile($sourcePath);

        for ($page = 1; $page <= $pageCount; $page++) {
            $template = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($template);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template);

            $dataUrl = $pageImages[$page] ?? null;
            if ($dataUrl === null) {
                continue;
            }
            $file = $this->imageFile($dataUrl);
            if ($file !== null) {
                $pdf->Image($file, 0, 0, (float) $size['width'], (float) $size['height'], 'PNG');
                @unlink($file);
            }
        }

        $pdf->Output('F', $outputPath);

        return $outputPath;
    }

    /** @param array<string,mixed> $field */
    private function drawField(Fpdi $pdf, array $field, float $pageWidth, float $pageHeight): void
    {
        $value = isset($field['value']) ? trim((string) $field['value']) : '';
        if ($value === '') {
            return;
        }

        $x = ((float) $field['x']) * $pageWidth;
        $y = ((float) $field['y']) * $pageHeight;
        $width = max(4.0, ((float) $field['width']) * $pageWidth);
        $height = max(3.5, ((float) $field['height']) * $pageHeight);
        $type = (string) $field['type'];

        // Handschrift/Unterschrift als Bild einpassen.
        if (FieldTypeRegistry::isHandwritingValue($value)) {
            $file = $this->imageFile($value);
            if ($file !== null) {
                $pdf->Image($file, $x, $y, $width, $height, 'PNG');
                @unlink($file);
            }

            return;
        }

        $pdf->SetTextColor(16, 36, 82);

        if ($type === 'checkbox' || ($type === 'yesno' && FieldTypeRegistry::options($field) === [])) {
            if (in_array($value, ['1', 'true', 'Ja', 'ja'], true)) {
                $pdf->SetFont('Helvetica', 'B', min(12.0, $height * 2.2));
                $pdf->Text($x + $width * 0.15, $y + $height * 0.85, 'X');
            }

            return;
        }

        if ($type === 'multiselect') {
            $value = implode(', ', FieldTypeRegistry::multiValues($value));
        }

        $fontSize = max(7.0, min(11.0, $height * 1.9));
        $pdf->SetFont('Helvetica', '', $fontSize);

        if ($type === 'textarea') {
            $pdf->SetXY($x, $y);
            $pdf->MultiCell($width, $fontSize * 0.5, $this->encode($value));

            return;
        }

        // Einzeilig: vertikal im Feld zentrieren, auf Feldbreite kürzen.
        $text = $this->encode($value);
        while ($text !== '' && $pdf->GetStringWidth($text) > $width) {
            $text = substr($text, 0, -1); // Windows-1252 ist Single-Byte.
        }
        $pdf->Text($x + 0.5, $y + $height / 2 + $fontSize * 0.18, $text);
    }

    private function imageFile(string $dataUrl): ?string
    {
        if (preg_match('#^data:image/png;base64,(.+)$#', $dataUrl, $m) !== 1) {
            return null;
        }
        $binary = base64_decode($m[1], true);
        if ($binary === false || $binary === '') {
            return null;
        }

        $base = tempnam(sys_get_temp_dir(), 'patsign_fld_');
        if ($base === false) {
            return null;
        }
        $file = $base . '.png';
        @unlink($base);

        return file_put_contents($file, $binary) !== false ? $file : null;
    }

    /** FPDF erwartet Windows-1252-kodierte Texte. */
    private function encode(string $text): string
    {
        return (string) mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    }
}
