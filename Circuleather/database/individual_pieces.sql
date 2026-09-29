USE circuleather;

-- One-time migration from shared inventory to separate physical tables.
-- Run after orders.sql, with a backup and application writes paused.
-- DDL commits separately in MariaDB; do not re-run after a successful migration.
DROP VIEW IF EXISTS individual_pieces;

CREATE TABLE individual_pieces LIKE batches;
ALTER TABLE individual_pieces
    ALTER COLUMN unit SET DEFAULT 'piece',
    ADD CONSTRAINT chk_individual_pieces_unit CHECK (unit = 'piece');

ALTER TABLE order_items
    MODIFY batch_id BIGINT UNSIGNED NULL,
    ADD COLUMN individual_piece_id BIGINT UNSIGNED NULL AFTER batch_id,
    ADD KEY idx_order_items_piece (individual_piece_id),
    ADD CONSTRAINT fk_order_items_piece FOREIGN KEY (individual_piece_id)
        REFERENCES individual_pieces (id) ON DELETE RESTRICT,
    ADD CONSTRAINT chk_order_items_inventory_reference CHECK (
        (batch_id IS NOT NULL AND individual_piece_id IS NULL)
        OR (batch_id IS NULL AND individual_piece_id IS NOT NULL)
    );

START TRANSACTION;

INSERT INTO individual_pieces (
    id, sku, material_name, grade, color, thickness, sale_price, cost_price,
    unit, stock, minimum_stock, origin, supplier, arrival_date,
    batch_photo_path, inspection_photo_path, created_at, updated_at
)
SELECT id, sku, material_name, grade, color, thickness, sale_price, cost_price,
    unit, stock, minimum_stock, origin, supplier, arrival_date,
    batch_photo_path, inspection_photo_path, created_at, updated_at
FROM batches WHERE unit = 'piece';

UPDATE order_items AS item
INNER JOIN individual_pieces AS piece ON piece.id = item.batch_id
SET item.individual_piece_id = piece.id, item.batch_id = NULL;

DELETE batch FROM batches AS batch
INNER JOIN individual_pieces AS piece ON piece.id = batch.id
WHERE batch.unit = 'piece';

COMMIT;

ALTER TABLE batches ADD CONSTRAINT chk_batches_unit CHECK (unit = 'kg');

GRANT SELECT, INSERT, UPDATE, DELETE ON circuleather.individual_pieces TO 'student'@'%';
