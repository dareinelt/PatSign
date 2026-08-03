-- Dokumentenkatalog: verwaltete PDF-Vorlagen mit Versionierung, Kategorien
-- und Zuweisung personalisierter Arbeitskopien zu Patientenmappen.

CREATE TABLE IF NOT EXISTS document_template_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_uuid CHAR(36) NOT NULL,
    name VARCHAR(190) NOT NULL,
    description TEXT NULL,
    document_type VARCHAR(120) NOT NULL DEFAULT 'Unbekannt',
    category_id BIGINT UNSIGNED NULL,
    current_version INT UNSIGNED NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_document_template_uuid (template_uuid),
    INDEX idx_document_templates_state (is_archived, is_active),
    INDEX idx_document_templates_category (category_id),
    CONSTRAINT fk_document_templates_category FOREIGN KEY (category_id)
        REFERENCES document_template_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_document_templates_created_by FOREIGN KEY (created_by)
        REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_document_templates_updated_by FOREIGN KEY (updated_by)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vorlagenversionen sind unveränderlich: jede Ersetzung erzeugt eine neue Zeile,
-- bestehende Zeilen werden niemals überschrieben oder gelöscht, solange sie
-- von Dokumenten referenziert werden (Löschschutz in der Anwendung).
CREATE TABLE IF NOT EXISTS document_template_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    file_path VARCHAR(1024) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    placeholders_json JSON NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_template_version (template_id, version),
    CONSTRAINT fk_template_versions_template FOREIGN KEY (template_id)
        REFERENCES document_templates(id) ON DELETE CASCADE,
    CONSTRAINT fk_template_versions_created_by FOREIGN KEY (created_by)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- documents: Verweis auf die verwendete Vorlagenversion (Katalogdokumente)
-- und Sortierreihenfolge innerhalb der Patientenmappe (idempotent).
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND COLUMN_NAME = 'template_version_id');
SET @sql := IF(@col = 0,
    'ALTER TABLE documents ADD COLUMN template_version_id BIGINT UNSIGNED NULL AFTER patient_folder_id, ADD INDEX idx_documents_template_version (template_version_id), ADD CONSTRAINT fk_documents_template_version FOREIGN KEY (template_version_id) REFERENCES document_template_versions(id)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND COLUMN_NAME = 'sort_order');
SET @sql := IF(@col = 0,
    'ALTER TABLE documents ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER template_version_id, ADD INDEX idx_documents_sort (case_number, sort_order)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
