-- Interaktive PDF-Formulare: Vorlagen, Felder, Feldtypen, Eingaben, Versionen.

-- Erweiterbare Feldtypen (Freitext, Checkbox, Datum, ...).
CREATE TABLE IF NOT EXISTS form_field_types (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(64) NOT NULL UNIQUE,
    label VARCHAR(190) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Formularvorlagen: pro Dokument versioniert (jede Neuanalyse erzeugt eine neue Version).
CREATE TABLE IF NOT EXISTS form_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_uuid CHAR(36) NOT NULL,
    document_id BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    source ENUM('acroform','vision','combined','manual') NOT NULL DEFAULT 'vision',
    status ENUM('analyzing','ready','error') NOT NULL DEFAULT 'analyzing',
    page_count INT UNSIGNED NOT NULL DEFAULT 0,
    field_count INT UNSIGNED NOT NULL DEFAULT 0,
    required_count INT UNSIGNED NOT NULL DEFAULT 0,
    analysis_model VARCHAR(120) NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_form_template_uuid (template_uuid),
    UNIQUE KEY uq_form_template_doc_version (document_id, version),
    INDEX idx_form_templates_document (document_id),
    CONSTRAINT fk_form_templates_document FOREIGN KEY (document_id) REFERENCES documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Formularfelder mit relativen Koordinaten (0–1, Ursprung oben links der Seite).
CREATE TABLE IF NOT EXISTS form_fields (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    field_uuid CHAR(36) NOT NULL,
    template_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(190) NOT NULL,
    type VARCHAR(64) NOT NULL,
    label VARCHAR(255) NULL,
    page INT UNSIGNED NOT NULL DEFAULT 1,
    x DECIMAL(9,6) NOT NULL DEFAULT 0,
    y DECIMAL(9,6) NOT NULL DEFAULT 0,
    width DECIMAL(9,6) NOT NULL DEFAULT 0,
    height DECIMAL(9,6) NOT NULL DEFAULT 0,
    required TINYINT(1) NOT NULL DEFAULT 0,
    options_json JSON NULL,
    validation_json JSON NULL,
    prefill_key VARCHAR(64) NULL,
    prefill_locked TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_form_field_uuid (field_uuid),
    INDEX idx_form_fields_template (template_id, page, sort_order),
    CONSTRAINT fk_form_fields_template FOREIGN KEY (template_id) REFERENCES form_templates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Formulareingaben: genau eine aktive Antwort pro Dokumentversion und Patientenmappe.
CREATE TABLE IF NOT EXISTS form_responses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    response_uuid CHAR(36) NOT NULL,
    template_id BIGINT UNSIGNED NOT NULL,
    document_id BIGINT UNSIGNED NOT NULL,
    case_number CHAR(8) NULL,
    status ENUM('in_progress','completed','signed') NOT NULL DEFAULT 'in_progress',
    filled_document_id CHAR(36) NULL,
    filled_pdf_path VARCHAR(1024) NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    signed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_form_response_uuid (response_uuid),
    UNIQUE KEY uq_form_response_template (template_id),
    INDEX idx_form_responses_document (document_id),
    CONSTRAINT fk_form_responses_template FOREIGN KEY (template_id) REFERENCES form_templates(id),
    CONSTRAINT fk_form_responses_document FOREIGN KEY (document_id) REFERENCES documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS form_response_values (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    response_id BIGINT UNSIGNED NOT NULL,
    field_id BIGINT UNSIGNED NOT NULL,
    value MEDIUMTEXT NULL,
    is_valid TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_form_value (response_id, field_id),
    CONSTRAINT fk_form_values_response FOREIGN KEY (response_id) REFERENCES form_responses(id),
    CONSTRAINT fk_form_values_field FOREIGN KEY (field_id) REFERENCES form_fields(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- documents: Kennzeichnung interaktiver Dokumente + Formularstatus (idempotent).
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND COLUMN_NAME = 'is_interactive');
SET @sql := IF(@col = 0,
    'ALTER TABLE documents ADD COLUMN is_interactive TINYINT(1) NOT NULL DEFAULT 0 AFTER status, ADD COLUMN form_status ENUM(''none'',''detected'',''analyzed'',''partial'',''complete'',''signed'',''error'') NOT NULL DEFAULT ''none'' AFTER is_interactive',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
