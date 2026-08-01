<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Repositories\SystemSettingRepository;
use App\Services\CompletionPageService;
use App\Services\SettingsService;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompletionPageServiceTest extends TestCase
{
    private CompletionPageService $service;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE system_settings (`key` TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $pdo->exec("INSERT INTO system_settings (`key`, value) VALUES
            ('general.clinic_name', 'Städtisches Klinikum Wolfenbüttel gGmbH'),
            ('general.clinic_location', 'Wolfenbüttel')");

        $settings = new SettingsService(new SystemSettingRepository($pdo), new Config(sys_get_temp_dir() . '/patsign-no-config'));
        $this->service = new CompletionPageService($settings, sys_get_temp_dir());
    }

    public function testRenderTemplateReplacesPlaceholders(): void
    {
        $result = $this->service->renderTemplate(
            'Der Patient ({nachname}, {vorname} ({geburtsdatum})) war am {datum} Patient im {klinik}.',
            [
                'nachname' => 'Vogel',
                'vorname' => 'Hans-Dieter',
                'geburtsdatum' => '01.02.1960',
                'datum' => '01.08.2026',
                'klinik' => 'Städtisches Klinikum',
            ]
        );

        self::assertSame('Der Patient (Vogel, Hans-Dieter (01.02.1960)) war am 01.08.2026 Patient im Städtisches Klinikum.', $result);
    }

    public function testPlaceholderValuesUsesSettingsAndFormatsDates(): void
    {
        $vars = $this->service->placeholderValues(
            [
                'last_name' => 'Vogel',
                'first_name' => 'Hans-Dieter',
                'birth_date' => '1960-02-01',
                'case_number' => '92546499',
                'document_type' => 'Patientenvertrag',
            ],
            [
                'final_name' => '92546499_VogelHansDieter_19600201_Patientenvertrag.pdf',
                'email' => 'patient@example.com',
                'operator' => 'admin',
                'signed_at' => '2026-08-01 16:48:00',
                'started_at' => strtotime('2026-08-01 16:30:00'),
            ]
        );

        self::assertSame('Vogel', $vars['nachname']);
        self::assertSame('Hans-Dieter', $vars['vorname']);
        self::assertSame('01.02.1960', $vars['geburtsdatum']);
        self::assertSame('92546499', $vars['fallnummer']);
        self::assertSame('Patientenvertrag', $vars['dokumententyp']);
        self::assertSame('92546499_VogelHansDieter_19600201_Patientenvertrag.pdf', $vars['dateiname']);
        self::assertSame('Städtisches Klinikum Wolfenbüttel gGmbH', $vars['klinik']);
        self::assertSame('Wolfenbüttel', $vars['ort']);
        self::assertSame('01.08.2026', $vars['datum']);
        self::assertSame('16:48', $vars['uhrzeit']);
        self::assertSame('16:30', $vars['beginn']);
        self::assertSame('admin', $vars['bearbeiter']);
    }

    public function testPlaceholderValuesFallsBackToSignedAtWhenStartMissing(): void
    {
        $vars = $this->service->placeholderValues(
            ['last_name' => 'Vogel'],
            ['final_name' => 'x.pdf', 'signed_at' => '2026-08-01 16:48:00', 'started_at' => 0]
        );

        self::assertSame('16:48', $vars['beginn']);
    }
}
