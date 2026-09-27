-- ==========================================================================
-- MarketLink: Admin module migration (run AFTER schema.sql)
-- phpMyAdmin > marketlink > Import. Safe to re-run.
-- Adds: activity_log table, triggers that log real events, one admin account.
-- Admin login:  admin@marketlink.local  /  Admin@123   (change it after first login)
-- ==========================================================================

USE marketlink;

CREATE TABLE IF NOT EXISTS activity_log (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  type          VARCHAR(40) NOT NULL,
  message       VARCHAR(255) NOT NULL,
  entity_type   VARCHAR(40) NULL,
  entity_id     INT UNSIGNED NULL,
  actor_user_id INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_activity_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- old trigger names from the standalone admin project
DROP TRIGGER IF EXISTS trg_farmers_au;

DROP TRIGGER IF EXISTS trg_users_ai;
DROP TRIGGER IF EXISTS trg_vendors_au;
DROP TRIGGER IF EXISTS trg_orders_ai;
DROP TRIGGER IF EXISTS trg_products_ai;
DROP TRIGGER IF EXISTS trg_products_ad;
DROP TRIGGER IF EXISTS trg_reviews_ai;

DELIMITER $$

-- Admin screens call vendors "farmers", so event types keep the farmer_ prefix.
CREATE TRIGGER trg_users_ai AFTER INSERT ON users FOR EACH ROW
BEGIN
  IF NEW.role = 'customer' THEN
    INSERT INTO activity_log (type, message, entity_type, entity_id)
    VALUES ('customer_registered', CONCAT('New customer registered: ', NEW.name), 'user', NEW.id);
  ELSEIF NEW.role = 'vendor' THEN
    INSERT INTO activity_log (type, message, entity_type, entity_id)
    VALUES ('farmer_registered', CONCAT('New farmer registered: ', NEW.name), 'user', NEW.id);
  END IF;
END$$

CREATE TRIGGER trg_vendors_au AFTER UPDATE ON vendors FOR EACH ROW
BEGIN
  IF NEW.is_active <> OLD.is_active THEN
    INSERT INTO activity_log (type, message, entity_type, entity_id)
    VALUES (IF(NEW.is_active = 1, 'farmer_approved', 'farmer_suspended'),
            CONCAT('Farmer ', IF(NEW.is_active = 1, 'approved', 'suspended'), ': ', NEW.stall_name),
            'farmer', NEW.id);
  END IF;
END$$

CREATE TRIGGER trg_orders_ai AFTER INSERT ON orders FOR EACH ROW
BEGIN
  INSERT INTO activity_log (type, message, entity_type, entity_id)
  VALUES ('order_placed', CONCAT('New order placed: ', COALESCE(NEW.order_number, CONCAT('#', NEW.id))), 'order', NEW.id);
END$$

CREATE TRIGGER trg_products_ai AFTER INSERT ON products FOR EACH ROW
BEGIN
  INSERT INTO activity_log (type, message, entity_type, entity_id)
  VALUES ('product_added', CONCAT('Product added: ', NEW.name), 'product', NEW.id);
END$$

CREATE TRIGGER trg_products_ad AFTER DELETE ON products FOR EACH ROW
BEGIN
  INSERT INTO activity_log (type, message, entity_type, entity_id)
  VALUES ('product_removed', CONCAT('Product removed: ', OLD.name), 'product', OLD.id);
END$$

CREATE TRIGGER trg_reviews_ai AFTER INSERT ON reviews FOR EACH ROW
BEGIN
  INSERT INTO activity_log (type, message, entity_type, entity_id)
  VALUES ('review_submitted', CONCAT('Review submitted (', NEW.rating, '/5)'), 'review', NEW.id);
END$$

DELIMITER ;

-- Default admin (bcrypt hash of Admin@123, same hashing the vendor login uses)
INSERT IGNORE INTO users (name, email, password_hash, role, is_active) VALUES
  ('MarketLink Admin', 'admin@marketlink.local',
   '$2y$10$tSV8i.tt.aKLNE4h1Dxyzu1ODzZBcWf4DUkGdugnETzNaBzPPKnzm', 'admin', 1);
