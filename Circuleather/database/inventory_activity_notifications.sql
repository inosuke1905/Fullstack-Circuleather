USE circuleather;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS inventory_notifications TINYINT(1) NOT NULL DEFAULT 1;

ALTER TABLE notifications
    MODIFY COLUMN type ENUM('low_stock', 'account_activity', 'order_activity', 'inventory_activity') NOT NULL,
    ADD COLUMN IF NOT EXISTS event_action ENUM('created', 'updated', 'deleted') NOT NULL DEFAULT 'updated',
    ADD COLUMN IF NOT EXISTS details_json LONGTEXT NULL;

GRANT SELECT, INSERT, UPDATE, DELETE ON circuleather.notifications TO 'student'@'%';
GRANT SELECT, INSERT, UPDATE ON circuleather.users TO 'student'@'%';
FLUSH PRIVILEGES;