<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** @param array<string,mixed> $server */
    public function __construct(
        private readonly array $server,
        /** @var array<string,mixed> */
        private readonly array $query,
        /** @var array<string,mixed> */
        private readonly array $post,
        /** @var array<string,mixed> */
        private readonly array $files,
        /** @var array<string,mixed> */
        private readonly array $session
    ) {}

    public static function capture(): self
    {
        return new self($_SERVER, $_GET, $_POST, $_FILES, $_SESSION ?? []);
    }

    public function method(): string
    {
        return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    public function path(): string
    {
        $uri = (string) ($this->server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        return $path === false ? '/' : $path;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->post);
    }

    /** @return array<string,mixed> */
    public function files(): array
    {
        return $this->files;
    }

    /** @return array<string,mixed> */
    public function session(): array
    {
        return $this->session;
    }
}
