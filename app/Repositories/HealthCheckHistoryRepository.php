<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Speichert Healthcheck-Snapshots und liefert den Verlauf der letzten 48 Stunden
 * als Stunden-Buckets für die Zeitstrahl-Darstellung.
 */
final class HealthCheckHistoryRepository
{
    public function __construct(private readonly ?PDO $pdo) {}

    /** @param array<int,array{key:string,label:string,status:string,detail:string}> $checks */
    public function record(array $checks): void
    {
        if ($this->pdo === null) {
            return;
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO health_check_history (component, status, detail) VALUES (:component, :status, :detail)'
            );
            foreach ($checks as $check) {
                $stmt->execute([
                    'component' => $check['key'],
                    'status' => in_array($check['status'], ['ok', 'warn', 'error'], true) ? $check['status'] : 'error',
                    'detail' => mb_substr($check['detail'], 0, 255),
                ]);
            }
        } catch (\Throwable) {
            // Verlauf ist optional – ein Ausfall der Historie darf den Healthcheck nicht brechen.
        }
    }

    /**
     * Verlauf der letzten 48h je Komponente als Stunden-Buckets.
     * Pro Bucket gilt der schlechteste gemessene Status (error > warn > ok).
     *
     * @return array<string,array<string,string>> component => [ 'YYYY-MM-DD HH' => status ]
     */
    public function timelineLast48h(): array
    {
        if ($this->pdo === null) {
            return [];
        }

        try {
            $stmt = $this->pdo->query(
                "SELECT component,
                        DATE_FORMAT(checked_at, '%Y-%m-%d %H') AS bucket,
                        MAX(CASE status WHEN 'error' THEN 2 WHEN 'warn' THEN 1 ELSE 0 END) AS severity
                 FROM health_check_history
                 WHERE checked_at >= NOW() - INTERVAL 48 HOUR
                 GROUP BY component, bucket"
            );

            $timeline = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $status = match ((int) $row['severity']) {
                    2 => 'error',
                    1 => 'warn',
                    default => 'ok',
                };
                $timeline[(string) $row['component']][(string) $row['bucket']] = $status;
            }

            return $timeline;
        } catch (\Throwable) {
            return [];
        }
    }

    /** Entfernt Einträge, die älter als 14 Tage sind. */
    public function prune(): void
    {
        if ($this->pdo === null) {
            return;
        }

        try {
            $this->pdo->exec('DELETE FROM health_check_history WHERE checked_at < NOW() - INTERVAL 14 DAY');
        } catch (\Throwable) {
            // Aufräumen ist Best-Effort.
        }
    }
}
