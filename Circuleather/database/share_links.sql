-- Stores hashes for random public share tokens, with expiration and revocation metadata.
-- The resource index supports finding active links for a batch, piece or order.
-- Deleting a creator keeps the link record and clears its optional user reference.


USE circuleather;

CREATE TABLE IF NOT EXISTS share_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_hash CHAR(64) NOT NULL,
    resource_type ENUM('batch', 'piece', 'order') NOT NULL,
    resource_id BIGINT UNSIGNED NOT NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_by_name VARCHAR(120) NOT NULL,
    language ENUM('nl', 'en') NOT NULL DEFAULT 'nl',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL DEFAULT NULL,
    revoked_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_share_links_token_hash (token_hash),
    KEY idx_share_links_resource (resource_type, resource_id, revoked_at),
    CONSTRAINT fk_share_links_user FOREIGN KEY (created_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

GRANT SELECT, INSERT, UPDATE ON circuleather.share_links TO 'student'@'%';
FLUSH PRIVILEGES;