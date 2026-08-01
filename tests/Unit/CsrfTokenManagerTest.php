<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Security\CsrfTokenManager;
use PHPUnit\Framework\TestCase;

final class CsrfTokenManagerTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testTokenValidationWorks(): void
    {
        $manager = new CsrfTokenManager();
        $token = $manager->token();

        self::assertTrue($manager->validate($token));
        self::assertFalse($manager->validate('invalid'));
    }
}
