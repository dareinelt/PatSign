<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Forms\FieldTypeRegistry;
use App\Services\Forms\FormValidationService;
use PHPUnit\Framework\TestCase;

final class FormValidationServiceTest extends TestCase
{
    private FormValidationService $service;

    protected function setUp(): void
    {
        $this->service = new FormValidationService(new FieldTypeRegistry());
    }

    /** @return array<int,array<string,mixed>> */
    private function fields(): array
    {
        return [
            ['field_uuid' => 'uuid-name', 'type' => 'text', 'required' => 1],
            ['field_uuid' => 'uuid-birth', 'type' => 'date', 'required' => 1],
            ['field_uuid' => 'uuid-note', 'type' => 'textarea', 'required' => 0],
        ];
    }

    public function testValidateAllReportsMissingRequiredFields(): void
    {
        $result = $this->service->validateAll($this->fields(), []);

        self::assertFalse($result['valid']);
        self::assertSame(2, $result['required_total']);
        self::assertSame(0, $result['filled_required']);
        self::assertContains('uuid-name', $result['missing_required']);
        self::assertContains('uuid-birth', $result['missing_required']);
        self::assertArrayNotHasKey('uuid-note', $result['errors']);
    }

    public function testValidateAllPassesWithCompleteValues(): void
    {
        $result = $this->service->validateAll($this->fields(), [
            'uuid-name' => 'Erika Musterfrau',
            'uuid-birth' => '01.01.1980',
        ]);

        self::assertTrue($result['valid']);
        self::assertSame([], $result['errors']);
        self::assertSame(2, $result['filled_required']);
    }

    public function testValidateAllReportsFormatErrors(): void
    {
        $result = $this->service->validateAll($this->fields(), [
            'uuid-name' => 'Erika',
            'uuid-birth' => 'ungültig',
        ]);

        self::assertFalse($result['valid']);
        self::assertArrayHasKey('uuid-birth', $result['errors']);
        self::assertSame([], $result['missing_required']);
        self::assertSame(1, $result['filled_required']);
    }

    public function testValidateFieldDelegatesToRegistry(): void
    {
        self::assertNull($this->service->validateField(['type' => 'email'], 'a@b.de'));
        self::assertNotNull($this->service->validateField(['type' => 'email'], 'nope'));
    }
}
