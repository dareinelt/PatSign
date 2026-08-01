<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class DeviceHistoryRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @param array<string,mixed> $context */
    public function log(?int $deviceId, string $eventType, array $context = [], ?int $userId = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO device_history (device_id, event_type, context_json, created_by) VALUES (:device_id, :event_type, :context_json, :created_by)'
        );
        $stmt->execute([
            'device_id' => $deviceId,
            'event_type' => $eventType,
            'context_json' => json_encode($context, JSON_UNESCAPED_UNICODE),
            'created_by' => $userId,
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function latest(int $limit = 50): array
    {
        $sql = 'SELECT h.*, d.name AS device_name, u.username AS created_by_name
                FROM device_history h
                LEFT JOIN devices d ON d.id = h.device_id
                LEFT JOIN users u ON u.id = h.created_by
                ORDER BY h.created_at DESC, h.id DESC
                LIMIT :limit';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
