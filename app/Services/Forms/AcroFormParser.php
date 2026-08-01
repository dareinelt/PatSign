<?php

declare(strict_types=1);

namespace App\Services\Forms;

/**
 * Best-Effort-Parser für PDF-Formularfelder (AcroForms).
 *
 * Liest Widget-Annotationen direkt aus der PDF-Objektstruktur und liefert
 * Felddefinitionen mit relativen Koordinaten (0–1, Ursprung oben links).
 * Stark komprimierte PDFs (Objektströme) werden – falls verfügbar – zuvor
 * mit qpdf oder Ghostscript in eine lesbare Form gebracht. Schlägt das
 * Parsen fehl, liefert der Parser eine leere Liste; die Vision-Analyse
 * übernimmt dann als Rückfallebene.
 */
final class AcroFormParser
{
    private const FLAG_REQUIRED = 2;        // /Ff Bit 2
    private const FLAG_MULTILINE = 4096;    // /Ff Bit 13 (Tx)
    private const FLAG_RADIO = 32768;       // /Ff Bit 16 (Btn)
    private const FLAG_PUSHBUTTON = 65536;  // /Ff Bit 17 (Btn)
    private const FLAG_COMBO = 131072;      // /Ff Bit 18 (Ch)

    /**
     * @return array<int,array<string,mixed>> Felder: name, type, label, page,
     *                                        x, y, width, height, required, options
     */
    public function parse(string $pdfPath): array
    {
        if (!is_file($pdfPath)) {
            return [];
        }

        try {
            $content = (string) file_get_contents($pdfPath);
            if ($content === '' || !str_contains($content, '/AcroForm')) {
                return [];
            }

            // Objektströme verbergen die Felddefinitionen – zunächst dekomprimieren.
            if (str_contains($content, '/ObjStm')) {
                $decompressed = $this->decompress($pdfPath);
                if ($decompressed === null) {
                    return [];
                }
                $content = $decompressed;
            }

            return $this->extractFields($content);
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function extractFields(string $content): array
    {
        $objects = $this->objects($content);
        $pages = $this->pages($objects);
        if ($pages === []) {
            return [];
        }

        $fields = [];
        $order = 0;
        foreach ($pages as $pageNumber => $page) {
            [$pageWidth, $pageHeight] = $page['size'];
            foreach ($page['annots'] as $ref) {
                $dict = $objects[$ref] ?? null;
                if ($dict === null || !str_contains($dict, '/Widget')) {
                    continue;
                }

                $field = $this->widgetToField($dict, $objects, $pageWidth, $pageHeight);
                if ($field === null) {
                    continue;
                }

                $field['page'] = $pageNumber;
                $field['sort_order'] = $order++;
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /** @param array<int,string> $objects @return array<string,mixed>|null */
    private function widgetToField(string $dict, array $objects, float $pageWidth, float $pageHeight): ?array
    {
        $merged = $dict . ' ' . $this->parentDict($dict, $objects);

        $ft = $this->name($merged, 'FT');
        if ($ft === null) {
            return null;
        }

        $flags = $this->int($merged, 'Ff') ?? 0;
        if ($ft === 'Btn' && ($flags & self::FLAG_PUSHBUTTON) !== 0) {
            return null; // Schaltflächen sind keine Eingabefelder.
        }

        $rect = $this->rect($dict);
        if ($rect === null || $pageWidth <= 0 || $pageHeight <= 0) {
            return null;
        }
        [$llx, $lly, $urx, $ury] = $rect;

        $type = match ($ft) {
            'Tx' => ($flags & self::FLAG_MULTILINE) !== 0 ? 'textarea' : 'text',
            'Btn' => ($flags & self::FLAG_RADIO) !== 0 ? 'radio' : 'checkbox',
            'Ch' => ($flags & self::FLAG_COMBO) !== 0 ? 'dropdown' : 'multiselect',
            'Sig' => 'signature',
            default => 'text',
        };

        return [
            'name' => $this->string($merged, 'T') ?? 'feld',
            'type' => $type,
            'label' => $this->string($merged, 'TU'),
            'x' => round(min($llx, $urx) / $pageWidth, 6),
            'y' => round(($pageHeight - max($lly, $ury)) / $pageHeight, 6),
            'width' => round(abs($urx - $llx) / $pageWidth, 6),
            'height' => round(abs($ury - $lly) / $pageHeight, 6),
            'required' => ($flags & self::FLAG_REQUIRED) !== 0,
            'options' => $this->optionList($merged),
        ];
    }

    /** @param array<int,string> $objects */
    private function parentDict(string $dict, array $objects): string
    {
        if (preg_match('/\/Parent\s+(\d+)\s+\d+\s+R/', $dict, $m) === 1) {
            return $objects[(int) $m[1]] ?? '';
        }

        return '';
    }

    /** @return array<int,string> Objektnummer => Objektinhalt */
    private function objects(string $content): array
    {
        $objects = [];
        if (preg_match_all('/(\d+)\s+\d+\s+obj\b(.*?)endobj/s', $content, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $m) {
                $objects[(int) $m[1]] = $m[2];
            }
        }

        return $objects;
    }

    /**
     * @param array<int,string> $objects
     * @return array<int,array{size:array{0:float,1:float},annots:list<int>}> Seitennummer (1-basiert) => Daten
     */
    private function pages(array $objects): array
    {
        $pageObjects = [];
        foreach ($objects as $num => $dict) {
            if (preg_match('/\/Type\s*\/Page\b(?!s)/', $dict) === 1) {
                $pageObjects[$num] = $dict;
            }
        }
        if ($pageObjects === []) {
            return [];
        }

        // Reihenfolge über den Seitenbaum ist aufwendig – Objektreihenfolge
        // entspricht in der Praxis fast immer der Seitenreihenfolge.
        ksort($pageObjects);

        $pages = [];
        $pageNumber = 1;
        foreach ($pageObjects as $num => $dict) {
            $pages[$pageNumber++] = [
                'size' => $this->mediaBox($dict, $objects, $num),
                'annots' => $this->annotRefs($dict, $objects),
            ];
        }

        return $pages;
    }

    /** @param array<int,string> $objects @return array{0:float,1:float} */
    private function mediaBox(string $dict, array $objects, int $objNum): array
    {
        $current = $dict;
        for ($depth = 0; $depth < 8; $depth++) {
            if (preg_match('/\/MediaBox\s*\[\s*([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s*\]/', $current, $m) === 1) {
                return [abs((float) $m[3] - (float) $m[1]), abs((float) $m[4] - (float) $m[2])];
            }
            if (preg_match('/\/Parent\s+(\d+)\s+\d+\s+R/', $current, $m) !== 1) {
                break;
            }
            $current = $objects[(int) $m[1]] ?? '';
        }

        return [612.0, 792.0]; // Letter als PDF-Standardgröße.
    }

    /** @param array<int,string> $objects @return list<int> */
    private function annotRefs(string $dict, array $objects): array
    {
        if (preg_match('/\/Annots\s*\[(.*?)\]/s', $dict, $m) === 1) {
            $body = $m[1];
        } elseif (preg_match('/\/Annots\s+(\d+)\s+\d+\s+R/', $dict, $m) === 1) {
            $body = $objects[(int) $m[1]] ?? '';
        } else {
            return [];
        }

        if (preg_match_all('/(\d+)\s+\d+\s+R/', $body, $refs) > 0) {
            return array_map('intval', $refs[1]);
        }

        return [];
    }

    private function name(string $dict, string $key): ?string
    {
        return preg_match('/\/' . $key . '\s*\/(\w+)/', $dict, $m) === 1 ? $m[1] : null;
    }

    private function int(string $dict, string $key): ?int
    {
        return preg_match('/\/' . $key . '\s+(-?\d+)/', $dict, $m) === 1 ? (int) $m[1] : null;
    }

    private function string(string $dict, string $key): ?string
    {
        if (preg_match('/\/' . $key . '\s*\(((?:\\\\.|[^\\\\)])*)\)/', $dict, $m) !== 1) {
            return null;
        }
        $value = stripcslashes($m[1]);
        // UTF-16BE-Strings (mit BOM) in UTF-8 umwandeln.
        if (str_starts_with($value, "\xFE\xFF")) {
            $value = (string) mb_convert_encoding(substr($value, 2), 'UTF-8', 'UTF-16BE');
        }
        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /** @return array{0:float,1:float,2:float,3:float}|null */
    private function rect(string $dict): ?array
    {
        if (preg_match('/\/Rect\s*\[\s*([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s*\]/', $dict, $m) !== 1) {
            return null;
        }

        return [(float) $m[1], (float) $m[2], (float) $m[3], (float) $m[4]];
    }

    /** @return list<string> */
    private function optionList(string $dict): array
    {
        if (preg_match('/\/Opt\s*\[(.*?)\]/s', $dict, $m) !== 1) {
            return [];
        }
        if (preg_match_all('/\(((?:\\\\.|[^\\\\)])*)\)/', $m[1], $opts) > 0) {
            return array_values(array_filter(array_map(
                static fn (string $o): string => trim(stripcslashes($o)),
                $opts[1]
            ), static fn (string $o): bool => $o !== ''));
        }

        return [];
    }

    /** Dekomprimiert Objektströme mit qpdf oder Ghostscript (falls installiert). */
    private function decompress(string $pdfPath): ?string
    {
        $base = tempnam(sys_get_temp_dir(), 'patsign_form_');
        if ($base === false) {
            return null;
        }
        $target = $base . '.pdf';
        @unlink($base);

        $commands = [
            sprintf('qpdf --qdf --object-streams=disable %s %s 2>/dev/null', escapeshellarg($pdfPath), escapeshellarg($target)),
            sprintf('gs -dBATCH -dNOPAUSE -q -sDEVICE=pdfwrite -dCompressStreams=false -sOutputFile=%s %s 2>/dev/null', escapeshellarg($target), escapeshellarg($pdfPath)),
        ];

        foreach ($commands as $cmd) {
            exec($cmd, $out, $code);
            if ($code === 0 && is_file($target)) {
                $content = (string) file_get_contents($target);
                @unlink($target);
                if ($content !== '') {
                    return $content;
                }
            }
        }
        @unlink($target);

        return null;
    }
}
