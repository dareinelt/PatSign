<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SignatureService;
use App\Support\FilenameNormalizer;
use PHPUnit\Framework\TestCase;

final class FilenameNormalizerTest extends TestCase
{
    public function testFinalFilenameRemovesUmlautsSpacesAndSpecialChars(): void
    {
        $service = new SignatureService(new FilenameNormalizer());
        $filename = $service->buildFinalFilename([
            'case_number' => '92612345',
            'last_name' => 'Müstermann',
            'first_name' => 'Max',
            'birth_date' => '12.03.1985',
            'document_type' => 'Aufklärungsbogen #1',
        ]);

        self::assertSame('92612345_MuestermannMax_12031985_Aufklaerungsbogen1.pdf', $filename);
    }
}
