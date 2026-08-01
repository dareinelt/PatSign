<?php

declare(strict_types=1);

namespace App\Services;

final class CaseNumberExtractor
{
    /** @return array{case_number:?string,confidence:float,candidates:array<int,string>} */
    public function extract(string $content, ?int $year = null): array
    {
        $year ??= (int) date('Y');
        // Akzeptierte Präfixe: aktuelles Jahr und die zwei Vorjahre
        // (z. B. 2025 → "925", "924", "923").
        $prefixes = array_map(
            static fn (int $y): string => '9' . substr((string) $y, -2),
            [$year, $year - 1, $year - 2]
        );

        preg_match_all('/\b\d{8}\b/', $content, $matches);
        $candidates = array_values(array_unique($matches[0] ?? []));

        $scored = [];
        foreach ($candidates as $candidate) {
            $score = 0.1;
            foreach ($prefixes as $index => $prefix) {
                if (str_starts_with($candidate, $prefix)) {
                    // Aktuelles Jahr minimal bevorzugen, damit bei mehreren
                    // gültigen Kandidaten die jüngste Fallnummer gewinnt.
                    $score = 0.9 - $index * 0.01;
                    if ($candidate === $prefix . '00000') {
                        $score -= 0.2;
                    }
                    break;
                }
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
