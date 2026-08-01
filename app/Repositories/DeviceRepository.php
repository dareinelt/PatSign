<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class DeviceRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $sql = 'INSERT INTO devices (device_uuid, name, device_type, browser, os, fingerprint, token_hash, software_version, status, last_seen_at, last_ip)
                VALUES (:device_uuid, :name, :device_type, :browser, :os, :fingerprint, :token_hash, :software_version, \'active\', NOW(), :last_ip)';
        $this->pdo->prepare($sql)->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM devices WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function findByUuid(string $uuid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM devices WHERE device_uuid = :uuid LIMIT 1');
        $stmt->execute(['uuid' => $uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM devices WHERE name = :name AND (:exclude IS NULL OR id <> :exclude) LIMIT 1');
        $stmt->execute(['name' => $name, 'exclude' => $excludeId]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return array<int,array<string,mixed>> Geräte inkl. aktueller Zuweisung */
    public function overview(): array
    {
        $sql = "SELECT d.*,
                       a.id AS assignment_id, a.case_number AS assigned_case_number,
                       a.patient_name AS assigned_patient, a.status AS assignment_status,
                       a.assigned_at AS assignment_assigned_at,
                       u.username AS assigned_by_name
                FROM devices d
                LEFT JOIN device_assignments a
                       ON a.device_id = d.id AND a.status IN ('pending','active')
                LEFT JOIN users u ON u.id = a.assigned_by
                ORDER BY d.name";

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function rename(int $id, string $name): void
    {
        $this->pdo->prepare('UPDATE devices SET name = :name WHERE id = :id')->execute(['name' => $name, 'id' => $id]);
    }

    public function updateStatus(int $id, string $status): void
    {
        $this->pdo->prepare('UPDATE devices SET status = :status WHERE id = :id')->execute(['status' => $status, 'id' => $id]);
    }

    public function updateTokenHash(int $id, string $tokenHash): void
    {
        $this->pdo->prepare('UPDATE devices SET token_hash = :hash WHERE id = :id')->execute(['hash' => $tokenHash, 'id' => $id]);
    }

    public function touch(int $id, string $ip, ?string $softwareVersion = null): void
    {
        $this->pdo->prepare(
            'UPDATE devices SET last_seen_at = NOW(), last_ip = :ip, software_version = COALESCE(:version, software_version) WHERE id = :id'
        )->execute(['ip' => $ip, 'version' => $softwareVersion, 'id' => $id]);
    }

    public function setLastUser(int $id, ?string $username): void
    {
        $this->pdo->prepare('UPDATE devices SET last_user = :u WHERE id = :id')->execute(['u' => $username, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM devices WHERE id = :id')->execute(['id' => $id]);
    }
}
