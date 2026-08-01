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

        return [
            'case_number' => $best,
            'confidence' => $best !== null ? $scored[$best] : 0.0,
            'candidates' => $candidates,
        ];
    }
}
