<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class DeviceSessionRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $sql = 'INSERT INTO device_sessions (session_uuid, device_id, assignment_id, token_hash, status, last_seen_at, expires_at)
                VALUES (:session_uuid, :device_id, :assignment_id, :token_hash, \'active\', NOW(), DATE_ADD(NOW(), INTERVAL :ttl MINUTE))';
        $this->pdo->prepare($sql)->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    public function findActiveForDevice(int $deviceId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM device_sessions WHERE device_id = :device_id AND status = 'active' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['device_id' => $deviceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function rotateToken(int $id, string $tokenHash, int $ttlMinutes): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE device_sessions SET token_hash = :hash, last_seen_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL :ttl MINUTE) WHERE id = :id'
        );
        $stmt->execute(['hash' => $tokenHash, 'ttl' => $ttlMinutes, 'id' => $id]);
    }

    public function touch(int $id, int $ttlMinutes): void
    {
        $this->pdo->prepare(
            'UPDATE device_sessions SET last_seen_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL :ttl MINUTE) WHERE id = :id'
        )->execute(['ttl' => $ttlMinutes, 'id' => $id]);
    }

    public function finish(int $id, string $status): void
    {
        $this->pdo->prepare('UPDATE device_sessions SET status = :status, ended_at = NOW() WHERE id = :id')
            ->execute(['status' => $status, 'id' => $id]);
    }

    public function endOpenForDevice(int $deviceId, string $status = 'ended'): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE device_sessions SET status = :status, ended_at = NOW() WHERE device_id = :device_id AND status = 'active'"
        );
        $stmt->execute(['status' => $status, 'device_id' => $deviceId]);

        return $stmt->rowCount();
    }

    public function expireStale(): int
    {
        return (int) $this->pdo->exec(
            "UPDATE device_sessions SET status = 'expired', ended_at = NOW() WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < NOW()"
        );
    }

    /** @return array<int,array<string,mixed>> Aktive Sitzungen inkl. Gerät und Fall */
    public function active(): array
    {
        $sql = "SELECT s.*, d.name AS device_name, a.case_number, a.patient_name
                FROM device_sessions s
                JOIN devices d ON d.id = s.device_id
                LEFT JOIN device_assignments a ON a.id = s.assignment_id
                WHERE s.status = 'active'
                ORDER BY s.started_at DESC";

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
