<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LocalAiClient;
use PHPUnit\Framework\TestCase;

final class LocalAiClientTest extends TestCase
{
    /** @param array<string,mixed> $config */
    private function baseUrl(array $config): string
    {
        $client = new LocalAiClient($config);
        $method = new \ReflectionMethod($client, 'baseUrl');

        return (string) $method->invoke($client);
    }

    public function testBaseUrlAppendsPortWhenMissing(): void
    {
        self::assertSame(
            'http://localhost:11434',
            $this->baseUrl(['host' => 'http://localhost', 'port' => 11434])
        );
    }

    public function testBaseUrlKeepsPortAlreadyInHost(): void
    {
        self::assertSame(
            'http://192.168.25.78:1234',
            $this->baseUrl(['host' => 'http://192.168.25.78:1234', 'port' => 11434])
        );
    }

    public function testBaseUrlStripsV1Suffix(): void
    {
        self::assertSame(
            'http://192.168.25.78:1234',
            $this->baseUrl(['host' => 'http://192.168.25.78:1234/v1', 'port' => 11434])
        );
    }

    public function testBaseUrlAddsSchemeWhenMissing(): void
    {
        self::assertSame(
            'http://analysis-api:11435',
            $this->baseUrl(['host' => 'analysis-api', 'port' => 11435])
        );
    }

    public function testBaseUrlPreservesPathWhenAppendingPort(): void
    {
        self::assertSame(
            'http://example.org:8080/llm',
            $this->baseUrl(['host' => 'http://example.org/llm', 'port' => 8080])
        );
    }
}
