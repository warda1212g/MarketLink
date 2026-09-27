-- ==========================================================================
-- MarketLink marketplace upgrade
-- Run AFTER schema.sql, admin-migration.sql, approval-google-migration.sql,
-- markets-migration.sql and favorites-migration.sql.
--
--  * customers no longer need admin approval (vendors still do)
--  * market categories (market_types) get an image, no location
--  * order lifecycle: "preparing" status, pickup slot chosen after the order
--  * cart, customer <-> vendor messaging, notifications
--  * content moderation: product/review moderation status, reports, log
-- ==========================================================================

-- ---------- 1. Customers are active immediately ----------
UPDATE users SET approval_status = 'approved' WHERE role = 'customer' AND approval_status = 'pending';

-- ---------- 2. Market categories ----------
ALTER TABLE market_types
  ADD COLUMN image VARCHAR(255) NULL AFTER icon;

-- ---------- 3. Orders ----------
ALTER TABLE orders
  MODIFY status ENUM('pending','accepted','preparing','ready','completed','declined','cancelled') NOT NULL DEFAULT 'pending',
  MODIFY pickup_date DATE NULL COMMENT 'NULL until the customer picks a pickup slot',
  ADD COLUMN pickup_selected_at DATETIME NULL AFTER pickup_end,
  ADD COLUMN cancel_reason VARCHAR(255) NULL AFTER customer_note,
  ADD COLUMN cancelled_by ENUM('customer','vendor') NULL AFTER cancel_reason,
  ADD COLUMN modified_at DATETIME NULL AFTER status_changed_at,
  ADD COLUMN reminder_sent_at DATETIME NULL AFTER modified_at;

-- ---------- 4. Moderation flags on existing content ----------
ALTER TABLE products
  ADD COLUMN moderation_status ENUM('active','inactive','flagged','removed') NOT NULL DEFAULT 'active' AFTER status,
  ADD KEY idx_products_moderation (moderation_status);

ALTER TABLE reviews
  ADD COLUMN status ENUM('visible','removed') NOT NULL DEFAULT 'visible' AFTER vendor_reply,
  ADD COLUMN moderated_at DATETIME NULL AFTER replied_at,
  ADD COLUMN moderated_by INT UNSIGNED NULL AFTER moderated_at,
  ADD KEY idx_reviews_status (status);

-- ---------- 5. Persistent cart (one row per customer + product) ----------
CREATE TABLE IF NOT EXISTS cart_items (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id INT UNSIGNED NOT NULL,
  product_id  INT UNSIGNED NOT NULL,
  quantity    DECIMAL(10,2) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cart_customer_product (customer_id, product_id),
  KEY idx_cart_product (product_id),
  CONSTRAINT fk_cart_customer FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_cart_product  FOREIGN KEY (product_id)  REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 6. Messaging (one thread per customer + vendor, linked to the latest order) ----------
CREATE TABLE IF NOT EXISTS conversations (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id     INT UNSIGNED NOT NULL,
  vendor_id       INT UNSIGNED NOT NULL,
  order_id        INT UNSIGNED NULL,
  last_message_at DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_conversation_pair (customer_id, vendor_id),
  KEY idx_conversations_vendor (vendor_id, last_message_at),
  KEY idx_conversations_customer (customer_id, last_message_at),
  KEY idx_conversations_order (order_id),
  CONSTRAINT fk_conversations_customer FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_conversations_vendor   FOREIGN KEY (vendor_id)   REFERENCES vendors (id) ON DELETE CASCADE,
  CONSTRAINT fk_conversations_order    FOREIGN KEY (order_id)    REFERENCES orders (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id INT UNSIGNED NOT NULL,
  sender_id       INT UNSIGNED NOT NULL,
  sender_role     ENUM('customer','vendor') NOT NULL,
  order_id        INT UNSIGNED NULL,
  body            TEXT NOT NULL,
  is_read         TINYINT(1) NOT NULL DEFAULT 0,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_messages_conversation (conversation_id, id),
  KEY idx_messages_unread (conversation_id, sender_role, is_read),
  KEY idx_messages_created (created_at),
  CONSTRAINT fk_messages_conversation FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE,
  CONSTRAINT fk_messages_sender       FOREIGN KEY (sender_id)       REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_messages_order        FOREIGN KEY (order_id)        REFERENCES orders (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 7. Notifications ----------
CREATE TABLE IF NOT EXISTS notifications (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED NOT NULL,
  type         VARCHAR(40) NOT NULL,
  title        VARCHAR(160) NOT NULL,
  message      VARCHAR(500) NULL,
  link         VARCHAR(255) NULL COMMENT 'app-relative deep link, e.g. order.php?id=5',
  related_type VARCHAR(30) NULL,
  related_id   INT UNSIGNED NULL,
  is_read      TINYINT(1) NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notifications_user_read (user_id, is_read, id),
  KEY idx_notifications_user_created (user_id, created_at),
  KEY idx_notifications_related (related_type, related_id),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 8. Reports + moderation log ----------
CREATE TABLE IF NOT EXISTS reports (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reporter_id   INT UNSIGNED NOT NULL,
  reporter_role ENUM('customer','vendor') NOT NULL,
  content_type  ENUM('product','review','vendor','message') NOT NULL,
  content_id    INT UNSIGNED NOT NULL,
  reason        VARCHAR(40) NOT NULL,
  details       VARCHAR(1000) NULL,
  status        ENUM('open','resolved','dismissed') NOT NULL DEFAULT 'open',
  action_taken  VARCHAR(60) NULL,
  resolved_by   INT UNSIGNED NULL,
  resolved_at   DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_reports_status (status, created_at),
  KEY idx_reports_content (content_type, content_id),
  KEY idx_reports_reporter (reporter_id),
  CONSTRAINT fk_reports_reporter FOREIGN KEY (reporter_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_reports_resolver FOREIGN KEY (resolved_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS moderation_logs (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id     INT UNSIGNED NULL,
  content_type VARCHAR(20) NOT NULL,
  content_id   INT UNSIGNED NOT NULL,
  action       VARCHAR(40) NOT NULL,
  reason       VARCHAR(500) NULL,
  report_id    INT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_modlog_content (content_type, content_id),
  KEY idx_modlog_created (created_at),
  CONSTRAINT fk_modlog_admin FOREIGN KEY (admin_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Messages hidden by a moderator keep their row (audit trail) but are no longer shown.
ALTER TABLE messages
  ADD COLUMN is_removed TINYINT(1) NOT NULL DEFAULT 0 AFTER is_read;

-- Customer confirms pickup (ready -> picked_up), then the vendor completes the order.
ALTER TABLE orders
  MODIFY status ENUM('pending','accepted','preparing','ready','picked_up','completed','declined','cancelled') NOT NULL DEFAULT 'pending',
  ADD COLUMN picked_up_at DATETIME NULL AFTER pickup_selected_at;
