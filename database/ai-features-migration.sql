-- MarketLink: AI features migration. Import AFTER your other migrations.
-- Safe to re-run. Adds products.harvest_date (Harvest Freshness Score).
USE marketlink;
SET @db = DATABASE();
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'products' AND COLUMN_NAME = 'harvest_date') = 0,
  'ALTER TABLE products ADD COLUMN harvest_date DATE NULL DEFAULT NULL AFTER unit',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
