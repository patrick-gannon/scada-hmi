-- ============================================================
-- HMI ADDITIONS — run this AFTER the existing schema.sql
-- Usage: mysql -u root -p scada < hmi_schema.sql
-- ============================================================

USE scada;

CREATE TABLE IF NOT EXISTS hmi_users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('admin','viewer') NOT NULL DEFAULT 'viewer',
    active        TINYINT(1) NOT NULL DEFAULT 1,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS hmi_nodes (
    node_id      VARCHAR(50) PRIMARY KEY,
    display_name VARCHAR(80) NOT NULL,
    location     VARCHAR(100),
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS thresholds (
    node_id        VARCHAR(50) PRIMARY KEY,
    temp_high      DECIMAL(5,2),
    temp_low       DECIMAL(5,2),
    humidity_high  DECIMAL(5,2),
    humidity_low   DECIMAL(5,2),
    alert_email    TINYINT(1) DEFAULT 1,
    alert_discord  TINYINT(1) DEFAULT 1,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS alarm_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    node_id    VARCHAR(50),
    field      VARCHAR(20),
    value      DECIMAL(7,2),
    direction  ENUM('HIGH','LOW'),
    threshold  DECIMAL(7,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_node_field (node_id, field),
    INDEX idx_created (created_at)
);
