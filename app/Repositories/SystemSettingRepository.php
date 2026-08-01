<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SystemSettingRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function get(string $key, ?string $default = null): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM system_settings WHERE `key` = :key LIMIT 1');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();

        return is_string($value) ? $value : $default;
    }

    /** @return array<string,string> */
    public function all(): array
    {
        try {
            $rows = $this->pdo->query('SELECT `key`, value FROM system_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($rows) ? $rows : [];
    }

    public function set(string $key, string $value): void
    {
        $sql = 'INSERT INTO system_settings (`key`, value) VALUES(:key, :value) ON DUPLICATE KEY UPDATE value = VALUES(value)';
        $this->pdo->prepare($sql)->execute(['key' => $key, 'value' => $value]);
    }
}
