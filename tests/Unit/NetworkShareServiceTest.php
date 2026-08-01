<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NetworkShareService;
use PHPUnit\Framework\TestCase;

final class NetworkShareServiceTest extends TestCase
{
    private NetworkShareService $service;

    protected function setUp(): void
    {
        $this->service = new NetworkShareService();
    }

    public function testRecognizesUncPaths(): void
    {
        self::assertTrue($this->service->isUncPath('\\\\Server\\Freigabeverzeichnis'));
        self::assertTrue($this->service->isUncPath('\\\\fileserver01\\scans\\eingang'));
    }

    public function testRejectsNonUncPaths(): void
    {
        self::assertFalse($this->service->isUncPath('/var/data/imports'));
        self::assertFalse($this->service->isUncPath('C:\\Daten\\Import'));
        self::assertFalse($this->service->isUncPath('\\\\ServerOhneFreigabe'));
        self::assertFalse($this->service->isUncPath(''));
    }

    public function testEmptyPathFails(): void
    {
        $result = $this->service->testConnection('');

        self::assertFalse($result['success']);
        self::assertSame('Bitte einen Pfad angeben.', $result['message']);
    }

    public function testLocalDirectorySucceeds(): void
    {
        $result = $this->service->testConnection(sys_get_temp_dir());

        self::assertTrue($result['success']);
    }

    public function testMissingLocalDirectoryFails(): void
    {
        $result = $this->service->testConnection('/nicht/vorhandenes/verzeichnis');

        self::assertFalse($result['success']);
    }
}
