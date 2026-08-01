<?php

declare(strict_types=1);

namespace App\Support;

final class FilenameNormalizer
{
    public function normalize(string $value): string
    {
        $value = str_replace(['Ä', 'Ö', 'Ü', 'ä', 'ö', 'ü', 'ß'], ['Ae', 'Oe', 'Ue', 'ae', 'oe', 'ue', 'ss'], $value);
        $value = preg_replace('/\s+/', '', $value) ?? $value;
        $value = preg_replace('/[^A-Za-z0-9_-]/', '', $value) ?? $value;

        return $value;
    }
}
