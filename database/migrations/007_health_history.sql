CREATE TABLE IF NOT EXISTS health_check_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    component VARCHAR(60) NOT NULL,
    status ENUM('ok','warn','error') NOT NULL,
    detail VARCHAR(255) NULL,
    checked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_health_component_time (component, checked_at),
    INDEX idx_health_checked_at (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
