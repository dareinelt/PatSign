<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Dokumentenkatalog: Vorlagen, unveränderliche Vorlagenversionen und Kategorien.
 */
final class DocumentTemplateRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /* ------------------------------------------------------------------ */
    /* Vorlagen                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Alle Vorlagen inkl. Kategorie, Ersteller und Dateiname der aktuellen Version.
     *
     * @return array<int,array<string,mixed>>
     */
    public function all(bool $includeArchived = true): array
    {
        $sql = 'SELECT t.*, c.name AS category_name, u.username AS created_by_name,
                       v.file_name AS current_file_name, v.id AS current_version_id,
                       v.placeholders_json AS current_placeholders_json,
                       (SELECT COUNT(*) FROM documents d
                        WHERE d.template_version_id IN (SELECT tv.id FROM document_template_versions tv WHERE tv.template_id = t.id)
                       ) AS usage_count
                FROM document_templates t
                LEFT JOIN document_template_categories c ON c.id = t.category_id
                LEFT JOIN users u ON u.id = t.created_by
                LEFT JOIN document_template_versions v ON v.template_id = t.id AND v.version = t.current_version'
            . ($includeArchived ? '' : ' WHERE t.is_archived = 0')
            . ' ORDER BY t.name';

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Aktive, nicht archivierte Vorlagen für die Auswahl im Personal-Dashboard.
     *
     * @return array<int,array<string,mixed>>
     */
    public function activeForSelection(string $search = '', int $categoryId = 0): array
    {
        $sql = 'SELECT t.id, t.name, t.description, t.document_type, t.current_version,
                       c.name AS category_name, c.id AS category_id
                FROM document_templates t
                LEFT JOIN document_template_categories c ON c.id = t.category_id
                WHERE t.is_active = 1 AND t.is_archived = 0';
        $params = [];
        if ($search !== '') {
            $sql .= ' AND (t.name LIKE :search OR t.description LIKE :search2 OR t.document_type LIKE :search3)';
            $like = '%' . $search . '%';
            $params += ['search' => $like, 'search2' => $like, 'search3' => $like];
        }
        if ($categoryId > 0) {
            $sql .= ' AND t.category_id = :category_id';
            $params['category_id'] = $categoryId;
        }
        $sql .= ' ORDER BY t.name';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM document_templates WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $sql = 'INSERT INTO document_templates (template_uuid, name, description, document_type, category_id, current_version, is_active, created_by, updated_by)
                VALUES (:template_uuid, :name, :description, :document_type, :category_id, 1, :is_active, :created_by, :updated_by)';
        $this->pdo->prepare($sql)->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateMetadata(int $id, string $name, ?string $description, string $documentType, ?int $categoryId, ?int $userId): void
    {
        $sql = 'UPDATE document_templates SET name = :name, description = :description,
                       document_type = :document_type, category_id = :category_id, updated_by = :updated_by
                WHERE id = :id';
        $this->pdo->prepare($sql)->execute([
            'name' => $name,
            'description' => $description,
            'document_type' => $documentType,
            'category_id' => $categoryId,
            'updated_by' => $userId,
            'id' => $id,
        ]);
    }

    public function setActive(int $id, bool $active, ?int $userId): void
    {
        $this->pdo->prepare('UPDATE document_templates SET is_active = :active, updated_by = :user_id WHERE id = :id')
            ->execute(['active' => $active ? 1 : 0, 'user_id' => $userId, 'id' => $id]);
    }

    public function setArchived(int $id, bool $archived, ?int $userId): void
    {
        // Archivierte Vorlagen werden gleichzeitig deaktiviert.
        $this->pdo->prepare('UPDATE document_templates SET is_archived = :archived, is_active = IF(:archived2 = 1, 0, is_active), updated_by = :user_id WHERE id = :id')
            ->execute(['archived' => $archived ? 1 : 0, 'archived2' => $archived ? 1 : 0, 'user_id' => $userId, 'id' => $id]);
    }

    /** Anzahl Dokumente, die eine Version dieser Vorlage verwenden (Löschschutz). */
    public function usageCount(int $templateId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM documents d
             INNER JOIN document_template_versions v ON v.id = d.template_version_id
             WHERE v.template_id = :id'
        );
        $stmt->execute(['id' => $templateId]);

        return (int) $stmt->fetchColumn();
    }

    /** Löschen nur, wenn keine Patientenmappe eine Version dieser Vorlage verwendet. */
    public function delete(int $templateId): bool
    {
        if ($this->usageCount($templateId) > 0) {
            return false;
        }
        $this->pdo->prepare('DELETE FROM document_template_versions WHERE template_id = :id')->execute(['id' => $templateId]);
        $this->pdo->prepare('DELETE FROM document_templates WHERE id = :id')->execute(['id' => $templateId]);

        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Versionen                                                           */
    /* ------------------------------------------------------------------ */

    /** Nächste Versionsnummer einer Vorlage (Versionen sind unveränderlich). */
    public function nextVersion(int $templateId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(version), 0) + 1 FROM document_template_versions WHERE template_id = :id');
        $stmt->execute(['id' => $templateId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Neue, unveränderliche Version anlegen und als aktuelle Version setzen.
     *
     * @param array<int,string> $placeholders
     */
    public function createVersion(int $templateId, int $version, string $filePath, string $fileName, int $fileSize, array $placeholders, ?int $userId): int
    {
        $this->pdo->prepare(
            'INSERT INTO document_template_versions (template_id, version, file_path, file_name, file_size, placeholders_json, created_by)
             VALUES (:template_id, :version, :file_path, :file_name, :file_size, :placeholders_json, :created_by)'
        )->execute([
            'template_id' => $templateId,
            'version' => $version,
            'file_path' => $filePath,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'placeholders_json' => json_encode($placeholders, JSON_UNESCAPED_UNICODE),
            'created_by' => $userId,
        ]);
        $versionId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('UPDATE document_templates SET current_version = :version, updated_by = :user_id WHERE id = :id')
            ->execute(['version' => $version, 'user_id' => $userId, 'id' => $templateId]);

        return $versionId;
    }

    public function findVersion(int $versionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM document_template_versions WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $versionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** Aktuelle Version einer Vorlage. */
    public function currentVersion(int $templateId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT v.* FROM document_template_versions v
             INNER JOIN document_templates t ON t.id = v.template_id AND t.current_version = v.version
             WHERE v.template_id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $templateId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<int,array<string,mixed>> */
    public function versions(int $templateId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT v.*, u.username AS created_by_name,
                    (SELECT COUNT(*) FROM documents d WHERE d.template_version_id = v.id) AS usage_count
             FROM document_template_versions v
             LEFT JOIN users u ON u.id = v.created_by
             WHERE v.template_id = :id ORDER BY v.version DESC'
        );
        $stmt->execute(['id' => $templateId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /* ------------------------------------------------------------------ */
    /* Kategorien                                                          */
    /* ------------------------------------------------------------------ */

    /** @return array<int,array<string,mixed>> */
    public function categories(bool $onlyActive = false): array
    {
        $sql = 'SELECT c.*, (SELECT COUNT(*) FROM document_templates t WHERE t.category_id = c.id) AS template_count
                FROM document_template_categories c'
            . ($onlyActive ? ' WHERE c.is_active = 1' : '')
            . ' ORDER BY c.name';

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function createCategory(string $name): int
    {
        $this->pdo->prepare('INSERT INTO document_template_categories (name) VALUES (:name)')->execute(['name' => $name]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateCategory(int $id, string $name): void
    {
        $this->pdo->prepare('UPDATE document_template_categories SET name = :name WHERE id = :id')
            ->execute(['name' => $name, 'id' => $id]);
    }

    /** Löschen nur, wenn keine Vorlage die Kategorie verwendet. */
    public function deleteCategory(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM document_templates WHERE category_id = :id');
        $stmt->execute(['id' => $id]);
        if ((int) $stmt->fetchColumn() > 0) {
            return false;
        }
        $this->pdo->prepare('DELETE FROM document_template_categories WHERE id = :id')->execute(['id' => $id]);

        return true;
    }
}
