-- Pantone Predictor Database Schema
-- Run: mysql -u root -p < schema.sql

CREATE DATABASE IF NOT EXISTS pantone_predictor
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE pantone_predictor;

-- ── Users ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(100) NOT NULL DEFAULT '',
    is_admin TINYINT(1) NOT NULL DEFAULT 0,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ── Settings (key-value) ───────────────────────────────────
CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ── Predictions ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS predictions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    series_name VARCHAR(200) NOT NULL,
    pms_number VARCHAR(30) NOT NULL,
    pms_name VARCHAR(100) NOT NULL DEFAULT '',
    lab_l DECIMAL(8,4) NOT NULL,
    lab_a DECIMAL(8,4) NOT NULL,
    lab_b DECIMAL(8,4) NOT NULL,
    confidence_score DECIMAL(5,2) NOT NULL DEFAULT 0,
    nearest_anchors JSON NULL,
    source ENUM('predicted','custom_lab') NOT NULL DEFAULT 'predicted',
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_series (series_name),
    INDEX idx_pms (pms_number),
    INDEX idx_source (source),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ── Prediction Components ──────────────────────────────────
CREATE TABLE IF NOT EXISTS prediction_components (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    prediction_id INT UNSIGNED NOT NULL,
    component_code VARCHAR(30) NOT NULL,
    component_description VARCHAR(200) NOT NULL DEFAULT '',
    percentage DECIMAL(8,6) NOT NULL,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    FOREIGN KEY (prediction_id) REFERENCES predictions(id) ON DELETE CASCADE,
    INDEX idx_prediction (prediction_id)
) ENGINE=InnoDB;

-- ── Audit Log ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS audit_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(50) NOT NULL,
    entity_type VARCHAR(50) NULL,
    entity_id VARCHAR(50) NULL,
    details TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_action (action),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- ── Default Settings ───────────────────────────────────────
INSERT INTO settings (setting_key, setting_value) VALUES
    ('cms_host', ''),
    ('cms_port', '1433'),
    ('cms_database', 'CMS'),
    ('cms_username', ''),
    ('cms_password', ''),
    ('cms_configured', '0'),
    ('app_name', 'Pantone Predictor'),
    ('prediction_k', '5'),
    ('noise_threshold', '2')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
