<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class DeviceAssignmentRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $sql = 'INSERT INTO device_assignments (assignment_uuid, device_id, case_number, patient_name, document_ids, assigned_by, status, expires_at)
                VALUES (:assignment_uuid, :device_id, :case_number, :patient_name, :document_ids, :assigned_by, \'pending\', DATE_ADD(NOW(), INTERVAL :ttl MINUTE))';
        $this->pdo->prepare($sql)->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM device_assignments WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** Offene (pending/active) Zuweisung eines Geräts. */
    public function findOpenForDevice(int $deviceId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.*, u.username AS assigned_by_name
             FROM device_assignments a
             LEFT JOIN users u ON u.id = a.assigned_by
             WHERE a.device_id = :device_id AND a.status IN ('pending','active')
             ORDER BY a.id DESC LIMIT 1"
        );
        $stmt->execute(['device_id' => $deviceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function markDelivered(int $id): void
    {
        $this->pdo->prepare("UPDATE device_assignments SET status = 'active', delivered_at = NOW() WHERE id = :id")->execute(['id' => $id]);
    }

    public function markCompleted(int $id): void
    {
        $this->pdo->prepare("UPDATE device_assignments SET status = 'completed', completed_at = NOW() WHERE id = :id")->execute(['id' => $id]);
    }

    public function cancelOpenForDevice(int $deviceId): int
    {
        $stmt = $this->pdo->prepare("UPDATE device_assignments SET status = 'cancelled' WHERE device_id = :device_id AND status IN ('pending','active')");
        $stmt->execute(['device_id' => $deviceId]);

        return $stmt->rowCount();
    }

    /** Setzt überfällige Zuweisungen auf "expired" und liefert betroffene Geräte-IDs. */
    public function expireStale(): array
    {
        $select = $this->pdo->query(
            "SELECT id, device_id FROM device_assignments WHERE status IN ('pending','active') AND expires_at IS NOT NULL AND expires_at < NOW()"
        );
        $rows = $select->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows !== []) {
            $ids = implode(',', array_map(static fn (array $r): int => (int) $r['id'], $rows));
            $this->pdo->exec("UPDATE device_assignments SET status = 'expired' WHERE id IN ({$ids})");
        }

        return $rows;
    }

    /** @return array<int,array<string,mixed>> Zuweisungsprotokoll */
    public function latest(int $limit = 50): array
    {
        $sql = 'SELECT a.*, d.name AS device_name, u.username AS assigned_by_name
                FROM device_assignments a
                LEFT JOIN devices d ON d.id = a.device_id
                LEFT JOIN users u ON u.id = a.assigned_by
                ORDER BY a.assigned_at DESC, a.id DESC
                LIMIT :limit';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
