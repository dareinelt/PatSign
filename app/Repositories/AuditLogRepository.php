<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class AuditLogRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @param array<string,mixed> $context */
    public function log(string $eventType, array $context = [], ?int $userId = null, ?int $documentId = null, ?string $ip = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_logs (event_type, user_id, document_id, context_json, ip_address) VALUES (:event_type, :user_id, :document_id, :context_json, :ip)'
        );
        $stmt->execute([
            'event_type' => $eventType,
            'user_id' => $userId,
            'document_id' => $documentId,
            'context_json' => json_encode($context, JSON_UNESCAPED_UNICODE),
            'ip' => $ip,
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function latest(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM audit_logs ORDER BY created_at DESC, id DESC LIMIT :limit');
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
