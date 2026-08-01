<?php

declare(strict_types=1);

namespace App\Core;

final class Config
{
    /** @var array<string,mixed> */
    private array $items = [];

    public function __construct(string $configPath)
    {
        foreach (glob(rtrim($configPath, '/') . '/*.php') ?: [] as $file) {
            $this->items[basename($file, '.php')] = require $file;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $current = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }
}
