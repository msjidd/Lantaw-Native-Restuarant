-- ============================================================
-- LANTAW NATIVE RESTAURANT - BUSINESS ANALYTICS SYSTEM
-- Database Schema (MySQL / MariaDB - XAMPP)
-- File: database/schema.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS lantaw_analytics
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE lantaw_analytics;

-- ------------------------------------------------------------
-- 1. users : system accounts (admin / staff)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS recommendations;
DROP TABLE IF EXISTS forecast_results;
DROP TABLE IF EXISTS import_logs;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS menu_items;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(50)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  full_name     VARCHAR(100) NOT NULL,
  email         VARCHAR(100) DEFAULT NULL,
  role          ENUM('admin','staff') NOT NULL DEFAULT 'staff',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at DATETIME     DEFAULT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  KEY idx_users_role (role)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 2. menu_items : restaurant menu catalogue
-- ------------------------------------------------------------
CREATE TABLE menu_items (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_name    VARCHAR(120) NOT NULL,
  description  VARCHAR(255) DEFAULT NULL,
  category     ENUM('Seafood','Meat','Chicken','Vegetables',
                    'Rice and Noodles','Beverages','Desserts','Other')
               NOT NULL DEFAULT 'Other',
  unit_price   DECIMAL(10,2) NOT NULL,
  is_available TINYINT(1) NOT NULL DEFAULT 1,
  is_sample    TINYINT(1) NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_menu_item_name (item_name),
  KEY idx_menu_category (category),
  KEY idx_menu_available (is_available),
  CONSTRAINT chk_menu_price CHECK (unit_price >= 0)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 3. orders : one customer transaction (header)
-- ------------------------------------------------------------
CREATE TABLE orders (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_reference VARCHAR(20) NOT NULL,
  order_date     DATE NOT NULL,
  order_type     ENUM('Dine-in','Takeout','Delivery') NOT NULL DEFAULT 'Dine-in',
  total_amount   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status         ENUM('completed','voided','cancelled') NOT NULL DEFAULT 'completed',
  status_reason  VARCHAR(255) DEFAULT NULL,
  is_sample      TINYINT(1) NOT NULL DEFAULT 0,
  created_by     INT UNSIGNED NOT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_reference (order_reference),
  KEY idx_orders_date (order_date),
  KEY idx_orders_status (status),
  KEY idx_orders_type (order_type),
  KEY idx_orders_created_by (created_by),
  CONSTRAINT fk_orders_user FOREIGN KEY (created_by)
    REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT chk_orders_total CHECK (total_amount >= 0)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 4. order_items : line items (price snapshot preserved)
-- ------------------------------------------------------------
CREATE TABLE order_items (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id     INT UNSIGNED NOT NULL,
  menu_item_id INT UNSIGNED NOT NULL,
  item_name    VARCHAR(120) NOT NULL,
  category     VARCHAR(30)  NOT NULL,
  unit_price   DECIMAL(10,2) NOT NULL,
  quantity     INT UNSIGNED NOT NULL,
  line_total   DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_oi_order (order_id),
  KEY idx_oi_menu_item (menu_item_id),
  KEY idx_oi_category (category),
  CONSTRAINT fk_oi_order FOREIGN KEY (order_id)
    REFERENCES orders (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_oi_menu FOREIGN KEY (menu_item_id)
    REFERENCES menu_items (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT chk_oi_quantity CHECK (quantity > 0),
  CONSTRAINT chk_oi_line_total CHECK (line_total >= 0)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 5. import_logs : Excel import history
-- ------------------------------------------------------------
CREATE TABLE import_logs (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED DEFAULT NULL,
  filename     VARCHAR(255) NOT NULL,
  file_size    INT UNSIGNED NOT NULL DEFAULT 0,
  import_type  VARCHAR(30)  NOT NULL,
  status       ENUM('success','partial','failed') NOT NULL DEFAULT 'success',
  total_rows   INT UNSIGNED NOT NULL DEFAULT 0,
  imported_rows INT UNSIGNED NOT NULL DEFAULT 0,
  skipped_rows INT UNSIGNED NOT NULL DEFAULT 0,
  error_rows   INT UNSIGNED NOT NULL DEFAULT 0,
  errors       TEXT,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_import_user (user_id),
  KEY idx_import_created (created_at),
  CONSTRAINT fk_import_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 6. forecast_results : saved forecast values
-- ------------------------------------------------------------
CREATE TABLE forecast_results (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  period_type   ENUM('daily','weekly','monthly') NOT NULL,
  period_start  DATE NOT NULL,
  forecast_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  actual_value  DECIMAL(12,2) DEFAULT NULL,
  method        VARCHAR(60) NOT NULL,
  split_type    ENUM('test','future') NOT NULL DEFAULT 'future',
  generated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by    INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_fc_period (period_type, period_start),
  KEY idx_fc_generated (generated_at),
  CONSTRAINT fk_fc_user FOREIGN KEY (created_by)
    REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 7. recommendations : prescriptive outputs
-- ------------------------------------------------------------
CREATE TABLE recommendations (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title          VARCHAR(160) NOT NULL,
  related_metric VARCHAR(80)  NOT NULL,
  evidence       TEXT NOT NULL,
  suggested_action TEXT NOT NULL,
  priority       ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
  metric_value   VARCHAR(60) DEFAULT NULL,
  generated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by     INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_rec_priority (priority),
  KEY idx_rec_generated (generated_at),
  CONSTRAINT fk_rec_user FOREIGN KEY (created_by)
    REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 8. audit_logs : security / change audit trail
-- ------------------------------------------------------------
CREATE TABLE audit_logs (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED DEFAULT NULL,
  action     VARCHAR(60) NOT NULL,
  entity     VARCHAR(40) DEFAULT NULL,
  entity_id  INT UNSIGNED DEFAULT NULL,
  details    TEXT,
  ip_address VARCHAR(45) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_user (user_id),
  KEY idx_audit_action (action),
  KEY idx_audit_created (created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Seed the first administrator securely (password is a HASH only).
-- Default one-time password: ChangeMe123!
-- The account MUST be changed after first login. The plaintext value
-- is never stored; it is hashed with PHP password_hash() below.
-- Generate a new hash with:
--   php -r "echo password_hash('YourNewPassword', PASSWORD_DEFAULT);"
-- ------------------------------------------------------------
INSERT INTO users (username, password_hash, full_name, email, role, is_active)
VALUES (
  'admin',
  '$2y$10$Mr237.cfNQSr55EYqS/Q5.OfB3TyHm1PC8rWzhdE3XMj4uAMtSciK',
  'System Administrator',
  'admin@lantaw.local',
  'admin',
  1
)
ON DUPLICATE KEY UPDATE username = username;

-- NOTE: the placeholder hash above is replaced by the installer
-- (setup_admin.php / bin/create_admin.php) with a real bcrypt hash.
-- If login fails, run:  php bin/create_admin.php
