-- Customer favourites (saved farmers/stalls and products). Safe to re-run.
-- The app also creates this table automatically on first use (includes/services/favorites.php).
USE marketlink;

CREATE TABLE IF NOT EXISTS favorites (
  customer_id INT UNSIGNED NOT NULL,
  item_type   ENUM('vendor','product') NOT NULL,
  item_id     INT UNSIGNED NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (customer_id, item_type, item_id),
  KEY idx_favorites_item (item_type, item_id),
  CONSTRAINT fk_favorites_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
