<?php

declare(strict_types=1);

namespace App\Services;

final class CaseNumberExtractor
{
    /** @return array{case_number:?string,confidence:float,candidates:array<int,string>} */
    public function extract(string $content, ?int $year = null): array
    {
        $year ??= (int) date('Y');
        $prefix = '9' . substr((string) $year, -2);

        preg_match_all('/\b\d{8}\b/', $content, $matches);
        $candidates = array_values(array_unique($matches[0] ?? []));

        $scored = [];
        foreach ($candidates as $candidate) {
            $score = str_starts_with($candidate, $prefix) ? 0.9 : 0.1;
            if ($candidate === $prefix . '00000') {
                $score -= 0.2;
            }
            $scored[$candidate] = max(0.0, min(1.0, $score));
        }

        arsort($scored);
        $best = array_key_first($scored);

        // PHP wandelt numerische String-Keys in Integer um – für den
        // nachgelagerten is_string()-Check muss die Fallnummer wieder
        // als String zurückgegeben werden.
        return [
            'case_number' => $best !== null ? (string) $best : null,
            'confidence' => $best !== null ? $scored[$best] : 0.0,
            'candidates' => $candidates,
        ];
    }
}
