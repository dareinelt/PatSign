<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Repositories\SystemSettingRepository;

/**
 * Liefert Einstellungen: Werte aus system_settings (DB, via Admin gepflegt)
 * überschreiben die Defaults aus config/*.php bzw. der .env.
 */
final class SettingsService
{
    /** @var array<string,string>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly SystemSettingRepository $settings,
        private readonly Config $config
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $db = $this->allFromDb();
        if (array_key_exists($key, $db)) {
            return $db[$key];
        }

        return $this->config->get($key, $default);
    }

    public function getString(string $key, string $default = ''): string
    {
        return (string) $this->get($key, $default);
    }

    public function getInt(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    public function getBool(string $key, bool $default = false): bool
    {
        return filter_var($this->get($key, $default), FILTER_VALIDATE_BOOL);
    }

    public function set(string $key, string $value): void
    {
        $this->settings->set($key, $value);
        $this->cache = null;
    }

    /** @param array<string,string> $values */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->settings->set($key, $value);
        }
        $this->cache = null;
    }

    /** @return array<string,string> */
    public function allFromDb(): array
    {
        if ($this->cache === null) {
            $this->cache = $this->settings->all();
        }

        return $this->cache;
    }
}
