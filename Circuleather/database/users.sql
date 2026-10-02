USE circuleather;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'worker') NOT NULL DEFAULT 'worker',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    language ENUM('nl', 'en') NOT NULL DEFAULT 'nl',
    theme ENUM('light', 'dark', 'system') NOT NULL DEFAULT 'light',
    low_stock_notifications TINYINT(1) NOT NULL DEFAULT 1,
    order_notifications TINYINT(1) NOT NULL DEFAULT 1,
    inventory_notifications TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS role ENUM('admin', 'worker') NOT NULL DEFAULT 'worker',
    ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS language ENUM('nl', 'en') NOT NULL DEFAULT 'nl',
    ADD COLUMN IF NOT EXISTS theme ENUM('light', 'dark', 'system') NOT NULL DEFAULT 'light',
    ADD COLUMN IF NOT EXISTS low_stock_notifications TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS order_notifications TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS inventory_notifications TINYINT(1) NOT NULL DEFAULT 1;

GRANT SELECT, INSERT, UPDATE, DELETE ON circuleather.users TO 'student'@'%';
FLUSH PRIVILEGES;
