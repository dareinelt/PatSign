CREATE TABLE IF NOT EXISTS roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(120) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id)
);

CREATE TABLE IF NOT EXISTS document_types (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS prompts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type ENUM('vision','analysis') NOT NULL,
    version INT UNSIGNED NOT NULL,
    content MEDIUMTEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    created_by VARCHAR(120) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_prompt_type_version (type, version)
);

CREATE TABLE IF NOT EXISTS documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id CHAR(36) NOT NULL,
    original_path VARCHAR(1024) NOT NULL,
    document_type VARCHAR(120) NOT NULL DEFAULT 'Unbekannt',
    case_number CHAR(8) NULL,
    first_name VARCHAR(120) NULL,
    last_name VARCHAR(120) NULL,
    birth_date DATE NULL,
    analysis_json JSON NULL,
    prompt_version_vision INT NULL,
    prompt_version_analysis INT NULL,
    analysis_model VARCHAR(120) NULL,
    analysis_duration_ms INT NULL,
    patient_key VARCHAR(512) NOT NULL,
    status ENUM('imported','analyzed','signed','archived') NOT NULL DEFAULT 'imported',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_document_id (document_id)
);

CREATE TABLE IF NOT EXISTS signatures (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id BIGINT UNSIGNED NOT NULL,
    completion_page_path VARCHAR(1024) NOT NULL,
    signed_pdf_path VARCHAR(1024) NOT NULL,
    consent_email TINYINT(1) NOT NULL DEFAULT 0,
    email_address VARCHAR(255) NULL,
    signed_at TIMESTAMP NOT NULL,
    signature_data MEDIUMTEXT NOT NULL,
    operator_name VARCHAR(120) NULL,
    clinic_name VARCHAR(120) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_signatures_document FOREIGN KEY (document_id) REFERENCES documents(id)
);

CREATE TABLE IF NOT EXISTS system_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(190) NOT NULL UNIQUE,
    value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(120) NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    document_id BIGINT UNSIGNED NULL,
    context_json JSON NULL,
    ip_address VARCHAR(64) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_event_type_created_at (event_type, created_at)
);
