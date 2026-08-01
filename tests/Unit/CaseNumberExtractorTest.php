<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CaseNumberExtractor;
use PHPUnit\Framework\TestCase;

final class CaseNumberExtractorTest extends TestCase
{
    public function testExtractUsesDynamicYearPrefix(): void
    {
        $service = new CaseNumberExtractor();
        $result = $service->extract('Werte: 92512345 und 92612345', 2026);

        self::assertSame('92612345', $result['case_number']);
        self::assertGreaterThanOrEqual(0.9, $result['confidence']);
    }

    public function testExtractReturnsNullWhenNoCandidateExists(): void
    {
        $service = new CaseNumberExtractor();
        $result = $service->extract('Keine 8-stellige Fallnummer');

        self::assertNull($result['case_number']);
        self::assertSame(0.0, $result['confidence']);
    }
}
