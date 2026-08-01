<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class NotificationRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function create(string $type, string $title, ?string $message = null, ?int $documentId = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO notifications (type, title, message, document_id) VALUES (:type, :title, :message, :document_id)'
        );
        $stmt->execute([
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'document_id' => $documentId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<int,array<string,mixed>> */
    public function latest(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, type, title, message, document_id, read_at, created_at
             FROM notifications
             ORDER BY created_at DESC, id DESC
             LIMIT :limit'
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function unreadCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM notifications WHERE read_at IS NULL')->fetchColumn();
    }

    public function markAllRead(): void
    {
        $this->pdo->exec('UPDATE notifications SET read_at = NOW() WHERE read_at IS NULL');
    }

    public function markRead(int $id): void
    {
        $this->pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE id = :id AND read_at IS NULL')
            ->execute(['id' => $id]);
    }
}
