-- Creates customers, orders and purchased-material snapshots with foreign-key constraints.
-- An order deletion cascades to its lines; referenced inventory and customers cannot be deleted.
-- This initial schema precedes individual_pieces.sql, which adds separate piece references.


USE circuleather;

CREATE TABLE IF NOT EXISTS clients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(150) NOT NULL,
    street_address VARCHAR(255) NOT NULL,
    postal_code VARCHAR(24) NOT NULL,
    city VARCHAR(100) NOT NULL,
    email VARCHAR(254) NOT NULL,
    phone VARCHAR(40) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_clients_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_number VARCHAR(32) NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    status ENUM('open', 'processing', 'shipped', 'delivered', 'cancelled') NOT NULL DEFAULT 'open',
    payment_status ENUM('unpaid', 'paid') NOT NULL DEFAULT 'unpaid',
    total_amount DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_by_name VARCHAR(120) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_orders_number (order_number),
    KEY idx_orders_client (client_id),
    KEY idx_orders_status (status),
    CONSTRAINT fk_orders_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    batch_id BIGINT UNSIGNED NOT NULL,
    sku VARCHAR(64) NULL,
    material_name VARCHAR(150) NOT NULL,
    grade VARCHAR(24) NOT NULL,
    color VARCHAR(80) NULL,
    thickness VARCHAR(40) NULL,
    quantity DECIMAL(10, 2) UNSIGNED NOT NULL,
    unit ENUM('kg', 'piece') NOT NULL,
    unit_price DECIMAL(10, 2) NOT NULL,
    line_total DECIMAL(12, 2) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_order_items_order (order_id),
    KEY idx_order_items_batch (batch_id),
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_items_batch FOREIGN KEY (batch_id) REFERENCES batches (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

GRANT SELECT, INSERT, UPDATE ON circuleather.clients TO 'student'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON circuleather.orders TO 'student'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON circuleather.order_items TO 'student'@'%';
FLUSH PRIVILEGES;
