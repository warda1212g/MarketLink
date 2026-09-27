-- MarketLink database schema (MySQL 5.7+ / MariaDB 10.3+)
-- Safe to re-run: every table uses CREATE TABLE IF NOT EXISTS.

CREATE DATABASE IF NOT EXISTS marketlink CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE marketlink;

CREATE TABLE IF NOT EXISTS users (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(120) NOT NULL,
  email          VARCHAR(190) NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  role           ENUM('customer','vendor','admin') NOT NULL DEFAULT 'customer',
  phone          VARCHAR(30) NULL,
  last_latitude  DECIMAL(10,7) NULL,
  last_longitude DECIMAL(10,7) NULL,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at  DATETIME NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vendors (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id             INT UNSIGNED NOT NULL,
  stall_name          VARCHAR(150) NOT NULL,
  contact_person      VARCHAR(120) NULL,
  phone               VARCHAR(30) NULL,
  email               VARCHAR(190) NULL,
  address             VARCHAR(255) NULL,
  description         TEXT NULL,
  operating_days      SET('mon','tue','wed','thu','fri','sat','sun') NULL,
  pickup_start        TIME NULL,
  pickup_end          TIME NULL,
  image               VARCHAR(255) NULL,
  latitude            DECIMAL(10,7) NULL,
  longitude           DECIMAL(10,7) NULL,
  location_address    VARCHAR(255) NULL,
  low_stock_threshold DECIMAL(10,2) NOT NULL DEFAULT 5.00,
  is_active           TINYINT(1) NOT NULL DEFAULT 1,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_vendors_user (user_id),
  KEY idx_vendors_lat_lng (latitude, longitude),
  KEY idx_vendors_lng (longitude),
  CONSTRAINT fk_vendors_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(80) NOT NULL,
  slug       VARCHAR(80) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO categories (name, slug, sort_order) VALUES
  ('Vegetables', 'vegetables', 1),
  ('Fruits', 'fruits', 2),
  ('Herbs & Spices', 'herbs-spices', 3),
  ('Dairy & Eggs', 'dairy-eggs', 4),
  ('Baked Goods', 'baked-goods', 5),
  ('Seasonal Produce', 'seasonal-produce', 6),
  ('Other', 'other', 99);

CREATE TABLE IF NOT EXISTS products (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_id   INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NULL,
  name        VARCHAR(150) NOT NULL,
  description TEXT NULL,
  price       DECIMAL(10,2) NOT NULL,
  unit        VARCHAR(20) NOT NULL DEFAULT 'kg',
  quantity    DECIMAL(10,2) NOT NULL DEFAULT 0,
  image       VARCHAR(255) NULL,
  status      ENUM('available','sold_out') NOT NULL DEFAULT 'available',
  deleted_at  DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_products_vendor (vendor_id, deleted_at),
  KEY idx_products_category (category_id),
  KEY idx_products_status (status),
  CONSTRAINT fk_products_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS weekly_stock (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_id   INT UNSIGNED NOT NULL,
  product_id  INT UNSIGNED NOT NULL,
  day_of_week TINYINT UNSIGNED NOT NULL COMMENT '1=Mon ... 7=Sun (ISO-8601)',
  quantity    DECIMAL(10,2) NOT NULL DEFAULT 0,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_weekly_stock (vendor_id, product_id, day_of_week),
  KEY idx_weekly_stock_product (product_id),
  CONSTRAINT fk_weekly_stock_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
  CONSTRAINT fk_weekly_stock_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_applications (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_id   INT UNSIGNED NOT NULL,
  day_of_week TINYINT UNSIGNED NOT NULL,
  week_start  DATE NOT NULL,
  items       INT UNSIGNED NOT NULL DEFAULT 0,
  applied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_stock_applications_vendor (vendor_id, applied_at),
  CONSTRAINT fk_stock_applications_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cutoff_settings (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_id          INT UNSIGNED NOT NULL,
  market_day         TINYINT UNSIGNED NOT NULL COMMENT '1=Mon ... 7=Sun',
  cutoff_days_before TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = same day as market',
  cutoff_time        TIME NOT NULL,
  pickup_start       TIME NOT NULL,
  pickup_end         TIME NOT NULL,
  is_active          TINYINT(1) NOT NULL DEFAULT 1,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cutoff_vendor_day (vendor_id, market_day),
  CONSTRAINT fk_cutoff_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id       INT UNSIGNED NOT NULL,
  vendor_id         INT UNSIGNED NOT NULL,
  order_number      VARCHAR(20) NULL,
  pickup_date       DATE NOT NULL,
  pickup_start      TIME NULL,
  pickup_end        TIME NULL,
  total_amount      DECIMAL(12,2) NOT NULL DEFAULT 0,
  status            ENUM('pending','accepted','ready','completed','declined','cancelled') NOT NULL DEFAULT 'pending',
  customer_note     VARCHAR(500) NULL,
  status_changed_at DATETIME NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_orders_number (order_number),
  KEY idx_orders_vendor_status (vendor_id, status),
  KEY idx_orders_vendor_created (vendor_id, created_at),
  KEY idx_orders_customer (customer_id),
  KEY idx_orders_status (status),
  KEY idx_orders_created (created_at),
  CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_orders_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id     INT UNSIGNED NOT NULL,
  product_id   INT UNSIGNED NOT NULL,
  product_name VARCHAR(150) NOT NULL,
  unit         VARCHAR(20) NOT NULL,
  quantity     DECIMAL(10,2) NOT NULL,
  unit_price   DECIMAL(10,2) NOT NULL,
  subtotal     DECIMAL(12,2) NOT NULL,
  KEY idx_order_items_order (order_id),
  KEY idx_order_items_product (product_id),
  CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_status_log (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id    INT UNSIGNED NOT NULL,
  from_status VARCHAR(20) NULL,
  to_status   VARCHAR(20) NOT NULL,
  changed_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_order_status_log_order (order_id),
  CONSTRAINT fk_order_status_log_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reviews (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id  INT UNSIGNED NOT NULL,
  vendor_id    INT UNSIGNED NOT NULL,
  order_id     INT UNSIGNED NULL,
  rating       TINYINT UNSIGNED NOT NULL,
  review       TEXT NULL,
  vendor_reply TEXT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  replied_at   DATETIME NULL,
  UNIQUE KEY uq_reviews_order (order_id),
  KEY idx_reviews_vendor (vendor_id, created_at),
  KEY idx_reviews_customer (customer_id),
  CONSTRAINT fk_reviews_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_reviews_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
  CONSTRAINT fk_reviews_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  CONSTRAINT chk_reviews_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS geocode_cache (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cache_key  CHAR(64) NOT NULL,
  kind       ENUM('search','reverse') NOT NULL,
  query      VARCHAR(255) NOT NULL,
  response   MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_geocode_cache_key (cache_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
