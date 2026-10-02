-- Upgrade adding creator IDs and display-name snapshots to inventory and orders.
-- Nullable fields preserve compatibility with records created before attribution was available.


USE circuleather;

ALTER TABLE batches
    ADD COLUMN IF NOT EXISTS created_by_user_id BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS created_by_name VARCHAR(120) NULL;

ALTER TABLE individual_pieces
    ADD COLUMN IF NOT EXISTS created_by_user_id BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS created_by_name VARCHAR(120) NULL;

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS created_by_user_id BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS created_by_name VARCHAR(120) NULL;