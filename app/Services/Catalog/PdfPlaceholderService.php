<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use RuntimeException;
use setasign\Fpdi\Fpdi;

/**
 * Platzhalter in PDF-Vorlagen ({{CASE_NUMBER}}, {{FIRST_NAME}}, ...).
 *
 * Personalisierung: Die Vorlage wird über FPDI unkomprimiert neu aufgebaut,
 * anschließend werden die Platzhalter direkt in den Inhaltsströmen ersetzt
 * und Stream-Längen sowie die XRef-Tabelle neu geschrieben. Die Vorlage
 * selbst bleibt unverändert; es entsteht immer eine neue Arbeitskopie.
 *
 * Hinweis: Die Ersetzung setzt textuell adressierbare Schriften voraus
 * (Standard-14-Fonts bzw. WinAnsi-kodierte Fonts). Bei Subset-Fonts mit
 * CID-Kodierung (z. B. Identity-H) sind Platzhalter im Datenstrom nicht
 * als Klartext auffindbar; solche Vorlagen werden beim Anlegen erkannt
 * (keine gefundenen Platzhalter) und im Admin-Bereich entsprechend angezeigt.
 */
final class PdfPlaceholderService
{
    public const PLACEHOLDER_PATTERN = '/\{\{([A-Z][A-Z0-9_]*)\}\}/';

    /**
     * Serverseitige Validierung einer hochgeladenen PDF-Vorlage:
     * gültiges PDF, Größenlimit, keine Verschlüsselung, nicht beschädigt.
     */
    public function validate(string $path, int $maxBytes): void
    {
        if (!is_file($path)) {
            throw new RuntimeException('Datei wurde nicht gefunden.');
        }

        $size = (int) filesize($path);
        if ($size <= 0) {
            throw new RuntimeException('Die Datei ist leer.');
        }
        if ($size > $maxBytes) {
            throw new RuntimeException('Datei überschreitet das Größenlimit von ' . round($maxBytes / 1048576, 1) . ' MB.');
        }

        $head = (string) file_get_contents($path, false, null, 0, 1024);
        if (!str_starts_with($head, '%PDF-')) {
            throw new RuntimeException('Die Datei ist kein gültiges PDF.');
        }

        $raw = (string) file_get_contents($path);
        if (preg_match('/\/Encrypt\s+\d+\s+\d+\s+R/', $raw) === 1) {
            throw new RuntimeException('Verschlüsselte PDF-Dateien werden nicht unterstützt.');
        }

        // Struktur über den FPDI-Parser prüfen (erkennt beschädigte Dateien).
        try {
            $pdf = new Fpdi();
            $pageCount = $pdf->setSourceFile($path);
            if ($pageCount < 1) {
                throw new RuntimeException('Das PDF enthält keine Seiten.');
            }
            $pdf->importPage(1);
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new RuntimeException('Das PDF ist beschädigt oder wird nicht unterstützt.');
        }
    }

    /**
     * Alle in der Vorlage gefundenen Platzhalterschlüssel (z. B. CASE_NUMBER).
     *
     * @return list<string>
     */
    public function extractPlaceholders(string $path): array
    {
        $raw = (string) file_get_contents($path);
        $found = [];

        foreach ($this->rawStreams($raw) as $stream) {
            $text = $this->normalizeStream($stream);
            if (preg_match_all(self::PLACEHOLDER_PATTERN, $text, $matches) > 0) {
                foreach ($matches[1] as $key) {
                    $found[$key] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Erzeugt eine personalisierte Arbeitskopie der Vorlage.
     *
     * @param array<string,string> $values Platzhalter => Wert
     * @return array{path:string,replaced:list<string>} Ausgabepfad und ersetzte Platzhalter
     */
    public function personalize(string $templatePath, array $values, string $outputPath, string $fallbackValue = ''): array
    {
        $rebuilt = $this->rebuildUncompressed($templatePath);
        [$document, $replaced] = $this->replaceInDocument($rebuilt, $values, $fallbackValue);

        $dir = dirname($outputPath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Ausgabeverzeichnis konnte nicht erstellt werden: ' . $dir);
        }
        if (file_put_contents($outputPath, $document) === false) {
            throw new RuntimeException('Personalisierte Kopie konnte nicht gespeichert werden.');
        }

        return ['path' => $outputPath, 'replaced' => $replaced];
    }

    /* ------------------------------------------------------------------ */
    /* Interna                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Baut die Vorlage über FPDI ohne Kompression neu auf, sodass alle
     * Inhaltsströme als Klartext vorliegen und eine klassische XRef-Tabelle
     * am Dateiende steht.
     */
    private function rebuildUncompressed(string $templatePath): string
    {
        try {
            $pdf = new Fpdi();
            $pdf->SetCompression(false);
            $pdf->SetAutoPageBreak(false);
            $pageCount = $pdf->setSourceFile($templatePath);

            for ($page = 1; $page <= $pageCount; $page++) {
                $template = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($template);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($template);
            }

            return $pdf->Output('S');
        } catch (\Throwable $e) {
            throw new RuntimeException('Vorlage konnte nicht verarbeitet werden: ' . $e->getMessage());
        }
    }

    /**
     * Ersetzt Platzhalter in allen Objekt-Streams, korrigiert /Length-Angaben
     * und baut die XRef-Tabelle mit den neuen Objekt-Offsets neu auf.
     *
     * @param array<string,string> $values
     * @return array{0:string,1:list<string>}
     */
    private function replaceInDocument(string $pdf, array $values, string $fallbackValue): array
    {
        $xrefPos = strrpos($pdf, "\nxref\n");
        if ($xrefPos === false) {
            throw new RuntimeException('PDF-Struktur unerwartet (keine XRef-Tabelle).');
        }
        $body = substr($pdf, 0, $xrefPos + 1); // inkl. abschließendem \n
        $tail = substr($pdf, $xrefPos + 1);

        if (preg_match('/trailer\s*(<<.*?>>)\s*startxref/s', $tail, $trailerMatch) !== 1) {
            throw new RuntimeException('PDF-Struktur unerwartet (kein Trailer).');
        }
        $trailerDict = $trailerMatch[1];

        // Objekte lokalisieren (FPDF/FPDI nummerieren fortlaufend mit Generation 0).
        if (preg_match_all('/(\d+) 0 obj/', $body, $objMatches, PREG_OFFSET_CAPTURE) < 1) {
            throw new RuntimeException('PDF-Struktur unerwartet (keine Objekte).');
        }

        $header = substr($body, 0, (int) $objMatches[0][0][1]);
        $objects = [];
        $count = count($objMatches[0]);
        foreach ($objMatches[0] as $i => $match) {
            $start = (int) $match[1];
            $end = $i + 1 < $count ? (int) $objMatches[0][$i + 1][1] : strlen($body);
            $objects[(int) $objMatches[1][$i][0]] = substr($body, $start, $end - $start);
        }

        $replaced = [];
        foreach ($objects as $number => $object) {
            $objects[$number] = $this->replaceInObject($object, $values, $fallbackValue, $replaced);
        }

        // Neu zusammensetzen und XRef mit korrigierten Offsets schreiben.
        $output = $header;
        $offsets = [];
        ksort($objects);
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($output);
            $output .= $object;
        }

        $maxObject = $objects === [] ? 0 : max(array_keys($objects));
        $xref = "xref\n0 " . ($maxObject + 1) . "\n0000000000 65535 f \n";
        for ($number = 1; $number <= $maxObject; $number++) {
            $xref .= isset($offsets[$number])
                ? sprintf("%010d 00000 n \n", $offsets[$number])
                : "0000000000 65535 f \n";
        }

        $startXref = strlen($output);
        $output .= $xref . 'trailer' . "\n" . $trailerDict . "\n" . "startxref\n" . $startXref . "\n%%EOF\n";

        return [$output, array_values(array_unique($replaced))];
    }

    /**
     * Platzhalter innerhalb eines einzelnen Objekts ersetzen; bei Änderungen
     * wird die /Length-Angabe des Streams angepasst.
     *
     * @param array<string,string> $values
     * @param list<string> $replaced
     */
    private function replaceInObject(string $object, array $values, string $fallbackValue, array &$replaced): string
    {
        $streamStart = strpos($object, "stream\n");
        if ($streamStart === false) {
            return $object;
        }
        $contentStart = $streamStart + strlen("stream\n");
        $streamEnd = strrpos($object, "\nendstream");
        if ($streamEnd === false || $streamEnd < $contentStart) {
            return $object;
        }

        $content = substr($object, $contentStart, $streamEnd - $contentStart);

        // FPDI bettet importierte Seiten als Form-XObjects ein, deren Streams
        // aus der Quelldatei komprimiert übernommen werden – hier dekomprimieren.
        $dict = substr($object, 0, $streamStart);
        $isFlate = str_contains($dict, '/FlateDecode');
        $plain = $content;
        if ($isFlate) {
            $inflated = @gzuncompress($content);
            if ($inflated === false) {
                return $object; // unbekannte Kompression: Objekt unverändert lassen
            }
            $plain = $inflated;
        }

        if (!str_contains($plain, '{')) {
            return $object;
        }

        $newContent = $plain;
        foreach ($values as $key => $value) {
            $pattern = $this->tolerantPattern('{{' . $key . '}}');
            $newContent = (string) preg_replace($pattern, $this->escapeValue($value), $newContent, -1, $hits);
            if ($hits > 0) {
                $replaced[] = $key;
            }
        }

        // Nicht befüllbare Platzhalter: Standardwert verwenden bzw. leeren.
        $newContent = (string) preg_replace(
            $this->tolerantGenericPattern(),
            $this->escapeValue($fallbackValue),
            $newContent
        );

        if ($newContent === $plain) {
            return $object;
        }

        $finalContent = $isFlate ? (string) gzcompress($newContent) : $newContent;
        $object = substr($object, 0, $contentStart) . $finalContent . substr($object, $streamEnd);

        // /Length auf die neue Streamlänge korrigieren (FPDF/FPDI schreiben direkte Werte).
        return (string) preg_replace('/\/Length\s+\d+/', '/Length ' . strlen($finalContent), $object, 1);
    }

    /**
     * Muster, das Kerning-Unterbrechungen in TJ-Arrays toleriert,
     * z. B. "({{CA)-3(SE_NUMBER}})".
     */
    private function tolerantPattern(string $literal): string
    {
        $chars = array_map(static fn (string $c): string => preg_quote($c, '/'), str_split($literal));

        return '/' . implode('(?:\)\s*-?\d+(?:\.\d+)?\s*\()?', $chars) . '/';
    }

    private function tolerantGenericPattern(): string
    {
        $break = '(?:\)\s*-?\d+(?:\.\d+)?\s*\()?';

        return '/\{' . $break . '\{' . $break . '(?:[A-Z0-9_]' . $break . ')+\}' . $break . '\}/';
    }

    /** PDF-Stringliterale escapen; Werte in Windows-1252 (FPDF-Standardfonts). */
    private function escapeValue(string $value): string
    {
        $encoded = (string) mb_convert_encoding(str_replace(["\r", "\n"], ' ', $value), 'Windows-1252', 'UTF-8');

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded);
    }

    /**
     * Rohdatenströme der Originaldatei (für die Platzhalter-Erkennung),
     * FlateDecode wird nach Möglichkeit dekomprimiert.
     *
     * @return list<string>
     */
    private function rawStreams(string $raw): array
    {
        $streams = [];
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $matches) > 0) {
            foreach ($matches[1] as $stream) {
                $inflated = @gzuncompress($stream);
                $streams[] = $inflated !== false ? $inflated : $stream;
            }
        }

        return $streams;
    }

    /** Kerning-Unterbrechungen entfernen, damit geteilte Platzhalter erkannt werden. */
    private function normalizeStream(string $stream): string
    {
        return (string) preg_replace('/\)\s*-?\d+(?:\.\d+)?\s*\(/', '', $stream);
    }
}
