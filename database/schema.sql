CREATE DATABASE IF NOT EXISTS scada;

USE scada;

-- ============================================================
-- CORE SCADA TABLES
-- ============================================================

CREATE TABLE IF NOT EXISTS environment (
    id INT AUTO_INCREMENT PRIMARY KEY,
    node_id VARCHAR(50) DEFAULT 'node_01',
    temperature DECIMAL(5,2),
    humidity DECIMAL(5,2),
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_name VARCHAR(50) UNIQUE,
    setting_value VARCHAR(50),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO settings (setting_name, setting_value) VALUES ('log_interval', '300');
INSERT INTO settings (setting_name, setting_value) VALUES ('logging_active', '1');

CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50),
    action VARCHAR(100),
    old_value VARCHAR(50),
    new_value VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

DELIMITER //

CREATE TRIGGER IF NOT EXISTS settings_audit
AFTER UPDATE ON settings
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (username, action, old_value, new_value)
    VALUES ('system', CONCAT('Updated ', OLD.setting_name), OLD.setting_value, NEW.setting_value);
END//

DELIMITER ;

-- ============================================================
-- HMI & PLUG CONTROL TABLES
-- ============================================================

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

CREATE TABLE IF NOT EXISTS kasa_plugs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plug_id VARCHAR(50) UNIQUE NOT NULL,
    display_name VARCHAR(80) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    location VARCHAR(100),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS plug_triggers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plug_id VARCHAR(50) NOT NULL,
    trigger_name VARCHAR(80) NOT NULL,
    trigger_type ENUM('manual', 'temp_high', 'temp_low', 'humidity_high', 'humidity_low', 'time_of_day') NOT NULL,
    node_id VARCHAR(50),
    threshold_value DECIMAL(5,2),
    time_value TIME,
    days_of_week VARCHAR(20) DEFAULT '0123456',
    action ENUM('turn_on', 'turn_off') NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (plug_id) REFERENCES kasa_plugs(plug_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS plug_actions_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plug_id VARCHAR(50) NOT NULL,
    action ENUM('turn_on', 'turn_off') NOT NULL,
    trigger_type VARCHAR(50),
    trigger_name VARCHAR(80),
    triggered_by VARCHAR(50),
    node_id VARCHAR(50),
    sensor_value DECIMAL(7,2),
    threshold_value DECIMAL(7,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_plug_created (plug_id, created_at)
);

