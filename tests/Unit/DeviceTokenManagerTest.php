<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Security\DeviceTokenManager;
use PHPUnit\Framework\TestCase;

final class DeviceTokenManagerTest extends TestCase
{
    private DeviceTokenManager $manager;

    protected function setUp(): void
    {
        $this->manager = new DeviceTokenManager();
    }

    public function testGeneratesValidUuidV4(): void
    {
        $uuid = $this->manager->generateUuid();

        $this->assertTrue($this->manager->isUuid($uuid));
        $this->assertSame('4', $uuid[14]);
    }

    public function testUuidsAreUnique(): void
    {
        $this->assertNotSame($this->manager->generateUuid(), $this->manager->generateUuid());
    }

    public function testTokenVerification(): void
    {
        $token = $this->manager->generateToken();
        $hash = $this->manager->hash($token);

        $this->assertSame(64, strlen($token));
        $this->assertTrue($this->manager->verify($token, $hash));
        $this->assertFalse($this->manager->verify('anderes-token', $hash));
        $this->assertFalse($this->manager->verify('', $hash));
    }

    public function testRejectsInvalidUuid(): void
    {
        $this->assertFalse($this->manager->isUuid('nicht-gueltig'));
        $this->assertFalse($this->manager->isUuid('00000000-0000-1000-8000-000000000000'));
    }
}
