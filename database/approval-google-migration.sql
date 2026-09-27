-- ==========================================================================
-- MarketLink: admin approval + Google sign-in (run AFTER admin-migration.sql)
-- phpMyAdmin > marketlink > Import. Safe to re-run. Works on MySQL and MariaDB.
--   * users.approval_status  pending | approved | suspended  (new sign-ups = pending)
--   * users.google_id        Google account ID for "Continue with Google"
--   * every account that already exists is marked approved
-- ==========================================================================

USE marketlink;

SET @db = DATABASE();

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'approval_status') = 0,
  "ALTER TABLE users ADD COLUMN approval_status ENUM('pending','approved','suspended') NOT NULL DEFAULT 'pending' AFTER is_active, ADD KEY idx_users_approval (approval_status)",
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Everyone who existed before this migration keeps their access.
-- (Runs only once: right after the column is created every row is 'pending'.)
SET @fresh = (SELECT COUNT(*) = SUM(approval_status = 'pending') FROM users);
UPDATE users SET approval_status = 'approved' WHERE @fresh = 1;
UPDATE users SET approval_status = 'approved' WHERE role = 'admin';

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'google_id') = 0,
  'ALTER TABLE users ADD COLUMN google_id VARCHAR(64) NULL AFTER password_hash, ADD UNIQUE KEY uq_users_google (google_id)',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Approval now lives on users; vendors.is_active is kept in sync by the admin action.
DROP TRIGGER IF EXISTS trg_vendors_au;
DROP TRIGGER IF EXISTS trg_users_au;

DELIMITER $$
CREATE TRIGGER trg_users_au AFTER UPDATE ON users FOR EACH ROW
BEGIN
  IF NEW.approval_status <> OLD.approval_status AND NEW.role IN ('vendor','customer') THEN
    INSERT INTO activity_log (type, message, entity_type, entity_id)
    VALUES (CONCAT(IF(NEW.role = 'vendor', 'farmer', 'customer'), '_', NEW.approval_status),
            CONCAT(IF(NEW.role = 'vendor', 'Vendor ', 'Customer '), NEW.approval_status, ': ', NEW.name),
            'user', NEW.id);
  END IF;
END$$
DELIMITER ;
