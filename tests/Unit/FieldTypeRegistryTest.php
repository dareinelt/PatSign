<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Forms\FieldTypeRegistry;
use PHPUnit\Framework\TestCase;

final class FieldTypeRegistryTest extends TestCase
{
    private FieldTypeRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new FieldTypeRegistry();
    }

    public function testRequiredFieldRejectsEmptyValue(): void
    {
        $field = ['type' => 'text', 'required' => true];

        self::assertNotNull($this->registry->validate($field, ''));
        self::assertNotNull($this->registry->validate($field, null));
        self::assertNull($this->registry->validate($field, 'Hallo'));
    }

    public function testOptionalFieldAcceptsEmptyValue(): void
    {
        self::assertNull($this->registry->validate(['type' => 'text', 'required' => false], ''));
    }

    public function testRequiredCheckboxMustBeChecked(): void
    {
        $field = ['type' => 'checkbox', 'required' => true];

        self::assertNotNull($this->registry->validate($field, '0'));
        self::assertNull($this->registry->validate($field, '1'));
    }

    public function testDateAcceptsGermanAndIsoFormat(): void
    {
        $field = ['type' => 'date', 'required' => false];

        self::assertNull($this->registry->validate($field, '24.12.2025'));
        self::assertNull($this->registry->validate($field, '2025-12-24'));
        self::assertNotNull($this->registry->validate($field, '32.13.2025'));
        self::assertNotNull($this->registry->validate($field, 'kein Datum'));
    }

    public function testTimeValidation(): void
    {
        $field = ['type' => 'time'];

        self::assertNull($this->registry->validate($field, '09:30'));
        self::assertNull($this->registry->validate($field, '23:59'));
        self::assertNotNull($this->registry->validate($field, '24:00'));
    }

    public function testNumberRangeRules(): void
    {
        $field = ['type' => 'number', 'validation' => ['min' => 0, 'max' => 120]];

        self::assertNull($this->registry->validate($field, '42'));
        self::assertNull($this->registry->validate($field, '3,5'));
        self::assertNotNull($this->registry->validate($field, '-1'));
        self::assertNotNull($this->registry->validate($field, '121'));
        self::assertNotNull($this->registry->validate($field, 'abc'));
    }

    public function testYesNoOnlyAcceptsJaOrNein(): void
    {
        $field = ['type' => 'yesno'];

        self::assertNull($this->registry->validate($field, 'Ja'));
        self::assertNull($this->registry->validate($field, 'nein'));
        self::assertNotNull($this->registry->validate($field, 'vielleicht'));
    }

    public function testChoiceFieldsRejectUnknownOptions(): void
    {
        $field = ['type' => 'dropdown', 'options' => ['A', 'B']];

        self::assertNull($this->registry->validate($field, 'A'));
        self::assertNotNull($this->registry->validate($field, 'C'));
    }

    public function testMultiselectValidatesEachValue(): void
    {
        $field = ['type' => 'multiselect', 'options' => ['Rot', 'Grün', 'Blau']];

        self::assertNull($this->registry->validate($field, "Rot\u{1F}Blau"));
        self::assertNotNull($this->registry->validate($field, "Rot\u{1F}Gelb"));
    }

    public function testEmailAndPhoneValidation(): void
    {
        self::assertNull($this->registry->validate(['type' => 'email'], 'max@example.org'));
        self::assertNotNull($this->registry->validate(['type' => 'email'], 'keine-mail'));
        self::assertNull($this->registry->validate(['type' => 'phone'], '+49 30 1234567'));
        self::assertNotNull($this->registry->validate(['type' => 'phone'], 'abc'));
    }

    public function testGenericRegexAndLengthRules(): void
    {
        $field = ['type' => 'text', 'validation' => ['min_length' => 3, 'max_length' => 5]];

        self::assertNotNull($this->registry->validate($field, 'ab'));
        self::assertNull($this->registry->validate($field, 'abcd'));
        self::assertNotNull($this->registry->validate($field, 'abcdef'));

        $regexField = ['type' => 'text', 'validation' => ['regex' => '^[0-9]{4}$']];
        self::assertNull($this->registry->validate($regexField, '1234'));
        self::assertNotNull($this->registry->validate($regexField, '12a4'));
    }

    public function testHandwritingValueSkipsTextRules(): void
    {
        $field = ['type' => 'text', 'validation' => ['min_length' => 500]];
        $handwriting = 'data:image/png;base64,iVBORw0KGgo=';

        self::assertTrue(FieldTypeRegistry::isHandwritingValue($handwriting));
        self::assertNull($this->registry->validate($field, $handwriting));
    }

    public function testSignatureRequiresHandwriting(): void
    {
        self::assertNotNull($this->registry->validate(['type' => 'signature'], 'Text'));
        self::assertNull($this->registry->validate(['type' => 'signature'], 'data:image/png;base64,AAAA'));
    }

    public function testUnknownTypeIsRejected(): void
    {
        self::assertNotNull($this->registry->validate(['type' => 'unbekannt'], 'x'));
    }

    public function testCustomTypeCanBeRegistered(): void
    {
        $this->registry->register('plz', 'Postleitzahl', static fn (string $v): ?string =>
            preg_match('/^\d{5}$/', $v) === 1 ? null : 'Ungültige PLZ.');

        self::assertTrue($this->registry->has('plz'));
        self::assertNull($this->registry->validate(['type' => 'plz'], '10115'));
        self::assertNotNull($this->registry->validate(['type' => 'plz'], '101'));
    }

    public function testMultiValuesSupportsSeparatorAndComma(): void
    {
        self::assertSame(['A', 'B'], FieldTypeRegistry::multiValues("A\u{1F}B"));
        self::assertSame(['A', 'B'], FieldTypeRegistry::multiValues('A, B'));
    }

    public function testOptionsFromJsonString(): void
    {
        self::assertSame(['Ja', 'Nein'], FieldTypeRegistry::options(['options_json' => '["Ja","Nein"]']));
    }
}
