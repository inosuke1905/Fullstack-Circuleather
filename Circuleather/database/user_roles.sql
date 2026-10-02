-- Upgrade for databases created before administrator/worker roles existed.
-- Preserves existing accounts and adds role/active defaults.
-- The final example shows how to promote a trusted account; it is not executed automatically.


USE circuleather;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS role ENUM('admin', 'worker') NOT NULL DEFAULT 'worker',
    ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1;

GRANT SELECT, INSERT, UPDATE, DELETE ON circuleather.users TO 'student'@'%';
FLUSH PRIVILEGES;

-- After applying this migration, promote a trusted existing account:
-- UPDATE users SET role = 'admin' WHERE email = 'owner@example.com';