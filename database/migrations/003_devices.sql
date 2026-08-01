CREATE TABLE IF NOT EXISTS devices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_uuid CHAR(36) NOT NULL,
    name VARCHAR(120) NOT NULL,
    device_type VARCHAR(60) NOT NULL DEFAULT 'tablet',
    browser VARCHAR(190) NULL,
    os VARCHAR(190) NULL,
    fingerprint VARCHAR(128) NULL,
    token_hash CHAR(64) NOT NULL,
    software_version VARCHAR(60) NULL,
    status ENUM('active','locked','retired') NOT NULL DEFAULT 'active',
    registered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMP NULL,
    last_ip VARCHAR(64) NULL,
    last_user VARCHAR(120) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_devices_uuid (device_uuid),
    UNIQUE KEY uq_devices_name (name),
    INDEX idx_devices_status (status),
    INDEX idx_devices_last_seen (last_seen_at)
);

CREATE TABLE IF NOT EXISTS device_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assignment_uuid CHAR(36) NOT NULL,
    device_id BIGINT UNSIGNED NOT NULL,
    case_number CHAR(8) NOT NULL,
    patient_name VARCHAR(255) NULL,
    document_ids JSON NOT NULL,
    assigned_by BIGINT UNSIGNED NULL,
    status ENUM('pending','active','completed','cancelled','expired') NOT NULL DEFAULT 'pending',
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delivered_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    UNIQUE KEY uq_assignment_uuid (assignment_uuid),
    INDEX idx_assignments_device_status (device_id, status),
    INDEX idx_assignments_case (case_number),
    CONSTRAINT fk_assignments_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
    CONSTRAINT fk_assignments_user FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS device_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_uuid CHAR(36) NOT NULL,
    device_id BIGINT UNSIGNED NOT NULL,
    assignment_id BIGINT UNSIGNED NULL,
    token_hash CHAR(64) NOT NULL,
    status ENUM('active','completed','ended','expired') NOT NULL DEFAULT 'active',
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    ended_at TIMESTAMP NULL,
    UNIQUE KEY uq_session_uuid (session_uuid),
    INDEX idx_sessions_device_status (device_id, status),
    CONSTRAINT fk_sessions_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
    CONSTRAINT fk_sessions_assignment FOREIGN KEY (assignment_id) REFERENCES device_assignments(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS device_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(120) NOT NULL,
    context_json JSON NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_history_device_created (device_id, created_at),
    INDEX idx_history_event (event_type, created_at),
    CONSTRAINT fk_history_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL
);
