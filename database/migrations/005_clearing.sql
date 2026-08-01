ALTER TABLE documents MODIFY status ENUM('imported','analyzing','analyzed','ready','signed','sent','archived','error','clearing') NOT NULL DEFAULT 'imported';

CREATE TABLE IF NOT EXISTS patient_folders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    folder_uuid CHAR(36) NOT NULL,
    case_number CHAR(8) NULL,
    first_name VARCHAR(120) NOT NULL,
    last_name VARCHAR(120) NOT NULL,
    birth_date DATE NULL,
    is_temporary TINYINT(1) NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_patient_folder_uuid (folder_uuid),
    INDEX idx_patient_folders_case_number (case_number),
    INDEX idx_patient_folders_name (last_name, first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MySQL 8 kennt kein "ADD COLUMN IF NOT EXISTS" – Spalte nur anlegen, wenn sie fehlt,
-- damit der Migrationslauf (führt alle Dateien erneut aus) idempotent bleibt.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND COLUMN_NAME = 'patient_folder_id');
SET @sql := IF(@col = 0,
    'ALTER TABLE documents ADD COLUMN patient_folder_id BIGINT UNSIGNED NULL AFTER patient_key, ADD INDEX idx_documents_patient_folder (patient_folder_id)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS clearing_error_reasons (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(64) NOT NULL UNIQUE,
    label VARCHAR(190) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clearing_cases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_uuid CHAR(36) NOT NULL,
    document_id BIGINT UNSIGNED NOT NULL,
    status ENUM('open','in_progress','assigned','completed') NOT NULL DEFAULT 'open',
    error_code VARCHAR(64) NOT NULL,
    ai_confidence DECIMAL(5,4) NULL,
    detected_values JSON NULL,
    corrected_values JSON NULL,
    assigned_patient_folder_id BIGINT UNSIGNED NULL,
    editor_user_id BIGINT UNSIGNED NULL,
    processing_started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_clearing_case_uuid (case_uuid),
    INDEX idx_clearing_status_created (status, created_at),
    INDEX idx_clearing_document (document_id),
    CONSTRAINT fk_clearing_document FOREIGN KEY (document_id) REFERENCES documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clearing_case_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    clearing_case_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(120) NOT NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_clearing_history_case (clearing_case_id, created_at),
    CONSTRAINT fk_clearing_history_case FOREIGN KEY (clearing_case_id) REFERENCES clearing_cases(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_analysis_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id BIGINT UNSIGNED NOT NULL,
    run_mode ENUM('initial','vision','analysis','both') NOT NULL DEFAULT 'initial',
    success TINYINT(1) NOT NULL DEFAULT 0,
    result_json JSON NULL,
    extracted_text MEDIUMTEXT NULL,
    error_message TEXT NULL,
    analysis_model VARCHAR(120) NULL,
    duration_ms INT NULL,
    triggered_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_analysis_runs_document (document_id, created_at),
    CONSTRAINT fk_analysis_runs_document FOREIGN KEY (document_id) REFERENCES documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
