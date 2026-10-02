USE circuleather;

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    recipient_user_id BIGINT UNSIGNED NOT NULL,
    type ENUM('low_stock', 'account_activity', 'order_activity', 'inventory_activity') NOT NULL,
    event_action ENUM('created', 'updated', 'deleted') NOT NULL DEFAULT 'updated',
    title VARCHAR(120) NOT NULL,
    message VARCHAR(255) NOT NULL,
    actor_name VARCHAR(120) NOT NULL DEFAULT 'System',
    details_json LONGTEXT NULL,
    target_url VARCHAR(255) NOT NULL DEFAULT '?page=inventory',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delivered_at TIMESTAMP NULL DEFAULT NULL,
    read_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_notifications_unread (recipient_user_id, read_at, created_at),
    KEY idx_notifications_delivery (recipient_user_id, delivered_at, id),
    CONSTRAINT fk_notifications_user FOREIGN KEY (recipient_user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE notifications
    MODIFY COLUMN type ENUM('low_stock', 'account_activity', 'order_activity', 'inventory_activity') NOT NULL,
    ADD COLUMN IF NOT EXISTS event_action ENUM('created', 'updated', 'deleted') NOT NULL DEFAULT 'updated',
    ADD COLUMN IF NOT EXISTS actor_name VARCHAR(120) NOT NULL DEFAULT 'System',
    ADD COLUMN IF NOT EXISTS details_json LONGTEXT NULL;

GRANT SELECT, INSERT, UPDATE, DELETE ON circuleather.notifications TO 'student'@'%';
FLUSH PRIVILEGES;

INSERT INTO notifications (recipient_user_id, type, title, message, target_url)
SELECT user.id, 'low_stock',
    IF(user.language = 'en', 'Low stock', 'Lage voorraad'),
    CONCAT(inventory.material_name, IF(user.language = 'en', ' is at or below minimum stock.', ' is op of onder de minimale voorraad.')),
    CONCAT('?page=batch-detail&id=', inventory.id)
FROM users AS user
INNER JOIN batches AS inventory ON inventory.stock <= inventory.minimum_stock
WHERE user.is_active = 1 AND user.low_stock_notifications = 1
    AND NOT EXISTS (
        SELECT 1 FROM notifications AS existing
        WHERE existing.recipient_user_id = user.id AND existing.type = 'low_stock'
            AND existing.target_url = CONCAT('?page=batch-detail&id=', inventory.id)
    );

INSERT INTO notifications (recipient_user_id, type, title, message, target_url)
SELECT user.id, 'low_stock',
    IF(user.language = 'en', 'Low stock', 'Lage voorraad'),
    CONCAT(inventory.material_name, IF(user.language = 'en', ' is at or below minimum stock.', ' is op of onder de minimale voorraad.')),
    CONCAT('?page=piece-detail&id=', inventory.id)
FROM users AS user
INNER JOIN individual_pieces AS inventory ON inventory.stock <= inventory.minimum_stock
WHERE user.is_active = 1 AND user.low_stock_notifications = 1
    AND NOT EXISTS (
        SELECT 1 FROM notifications AS existing
        WHERE existing.recipient_user_id = user.id AND existing.type = 'low_stock'
            AND existing.target_url = CONCAT('?page=piece-detail&id=', inventory.id)
    );