CREATE DATABASE scada;

USE scada;

CREATE TABLE environment (
    id INT AUTO_INCREMENT PRIMARY KEY,
    node_id VARCHAR(50) DEFAULT 'node_01',
    temperature DECIMAL(5,2),
    humidity DECIMAL(5,2),
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_name VARCHAR(50) UNIQUE,
    setting_value VARCHAR(50),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO settings (setting_name, setting_value) VALUES ('log_interval', '300');
INSERT INTO settings (setting_name, setting_value) VALUES ('logging_active', '1');

CREATE TABLE audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50),
    action VARCHAR(100),
    old_value VARCHAR(50),
    new_value VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TRIGGER settings_audit
AFTER UPDATE ON settings
FOR EACH ROW
INSERT INTO audit_log (username, action, old_value, new_value)
VALUES ('system', CONCAT('Updated ', OLD.setting_name), OLD.setting_value, NEW.setting_value);
