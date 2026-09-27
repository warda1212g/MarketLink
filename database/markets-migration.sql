-- ==========================================================================
-- MarketLink: Market management (run AFTER approval-google-migration.sql)
-- phpMyAdmin > marketlink > Import. Safe to re-run. Works on MySQL and MariaDB.
--
-- Hierarchy:  market_types -> markets -> farmer_markets <- vendors (farmers)
--             market_types -> categories (parent_id = subcategories) -> products
--             products.market_id ties each listing to the market it is sold in.
--
-- Existing tables are EXTENDED, not duplicated:
--   * vendors   is the farmer / vendor table (1:1 with users where role = 'vendor')
--   * categories gains market_type_id, parent_id (subcategories), is_active
--   * products  gains market_id, subcategory_id
--   * users.approval_status gains 'rejected'
-- ==========================================================================

USE marketlink;

SET @db = DATABASE();

-- -------------------------------------------------------------- market types
CREATE TABLE IF NOT EXISTS market_types (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(80)  NOT NULL,
  slug         VARCHAR(80)  NOT NULL,
  farmer_label VARCHAR(80)  NOT NULL COMMENT 'e.g. "Fruit Farmer" - used as the farmer category',
  icon         VARCHAR(16)  NULL COMMENT 'emoji shown on cards',
  description  VARCHAR(500) NULL,
  sort_order   INT NOT NULL DEFAULT 0,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_market_types_slug (slug),
  UNIQUE KEY uq_market_types_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------- markets
CREATE TABLE IF NOT EXISTS markets (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  market_type_id INT UNSIGNED NOT NULL,
  name           VARCHAR(150) NOT NULL,
  slug           VARCHAR(170) NOT NULL,
  description    TEXT NULL,
  image          VARCHAR(255) NULL,
  city           VARCHAR(80)  NOT NULL,
  area           VARCHAR(120) NULL,
  address        VARCHAR(255) NULL,
  latitude       DECIMAL(10,7) NULL,
  longitude      DECIMAL(10,7) NULL,
  opening_time   TIME NULL,
  closing_time   TIME NULL,
  operating_days SET('mon','tue','wed','thu','fri','sat','sun') NULL,
  status         ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
  created_by     INT UNSIGNED NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_markets_slug (slug),
  UNIQUE KEY uq_markets_name_city (name, city),
  KEY idx_markets_type (market_type_id),
  KEY idx_markets_status (status),
  KEY idx_markets_city (city),
  CONSTRAINT fk_markets_type    FOREIGN KEY (market_type_id) REFERENCES market_types(id) ON DELETE RESTRICT,
  CONSTRAINT fk_markets_creator FOREIGN KEY (created_by)     REFERENCES users(id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------- farmer <-> market (many-to-many)
CREATE TABLE IF NOT EXISTS farmer_markets (
  vendor_id   INT UNSIGNED NOT NULL,
  market_id   INT UNSIGNED NOT NULL,
  status      ENUM('active','suspended') NOT NULL DEFAULT 'active',
  assigned_by INT UNSIGNED NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (vendor_id, market_id),
  KEY idx_farmer_markets_market (market_id, status),
  CONSTRAINT fk_farmer_markets_vendor FOREIGN KEY (vendor_id)   REFERENCES vendors(id) ON DELETE CASCADE,
  CONSTRAINT fk_farmer_markets_market FOREIGN KEY (market_id)   REFERENCES markets(id) ON DELETE CASCADE,
  CONSTRAINT fk_farmer_markets_admin  FOREIGN KEY (assigned_by) REFERENCES users(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------- farmer categories (many-to-many)
-- A farmer category is a market type seen from the farmer's side ("Fruit Farmer").
CREATE TABLE IF NOT EXISTS farmer_categories (
  vendor_id      INT UNSIGNED NOT NULL,
  market_type_id INT UNSIGNED NOT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (vendor_id, market_type_id),
  KEY idx_farmer_categories_type (market_type_id),
  CONSTRAINT fk_farmer_categories_vendor FOREIGN KEY (vendor_id)      REFERENCES vendors(id)      ON DELETE CASCADE,
  CONSTRAINT fk_farmer_categories_type   FOREIGN KEY (market_type_id) REFERENCES market_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------- categories: market type + subcategories
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'market_type_id') = 0,
  'ALTER TABLE categories
     ADD COLUMN market_type_id INT UNSIGNED NULL AFTER id,
     ADD COLUMN parent_id INT UNSIGNED NULL AFTER market_type_id,
     ADD COLUMN description VARCHAR(255) NULL AFTER slug,
     ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER sort_order,
     ADD KEY idx_categories_type (market_type_id),
     ADD KEY idx_categories_parent (parent_id),
     ADD CONSTRAINT fk_categories_type   FOREIGN KEY (market_type_id) REFERENCES market_types(id) ON DELETE SET NULL,
     ADD CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id)      REFERENCES categories(id)   ON DELETE CASCADE',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -------------------------------------------------------------- products: market + subcategory
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'products' AND COLUMN_NAME = 'market_id') = 0,
  'ALTER TABLE products
     ADD COLUMN market_id INT UNSIGNED NULL AFTER vendor_id,
     ADD COLUMN subcategory_id INT UNSIGNED NULL AFTER category_id,
     ADD KEY idx_products_market (market_id, deleted_at),
     ADD KEY idx_products_subcategory (subcategory_id),
     ADD CONSTRAINT fk_products_market      FOREIGN KEY (market_id)      REFERENCES markets(id)    ON DELETE SET NULL,
     ADD CONSTRAINT fk_products_subcategory FOREIGN KEY (subcategory_id) REFERENCES categories(id) ON DELETE SET NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -------------------------------------------------------------- users: add 'rejected'
SET @sql = IF((SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'approval_status') NOT LIKE '%rejected%',
  "ALTER TABLE users MODIFY approval_status ENUM('pending','approved','suspended','rejected') NOT NULL DEFAULT 'pending'",
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -------------------------------------------------------------- suggested product catalogue
-- Names farmers pick from when listing a product (they may still type their own).
CREATE TABLE IF NOT EXISTS catalog_products (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NOT NULL,
  name        VARCHAR(120) NOT NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_catalog_products (category_id, name),
  CONSTRAINT fk_catalog_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------- activity log trigger for markets
DROP TRIGGER IF EXISTS trg_markets_ai;
DROP TRIGGER IF EXISTS trg_markets_au;
DELIMITER $$
CREATE TRIGGER trg_markets_ai AFTER INSERT ON markets FOR EACH ROW
BEGIN
  INSERT INTO activity_log (type, message, entity_type, entity_id, actor_user_id)
  VALUES ('market_created', CONCAT('Market created: ', NEW.name), 'market', NEW.id, NEW.created_by);
END$$
CREATE TRIGGER trg_markets_au AFTER UPDATE ON markets FOR EACH ROW
BEGIN
  IF NEW.status <> OLD.status THEN
    INSERT INTO activity_log (type, message, entity_type, entity_id)
    VALUES (CONCAT('market_', NEW.status), CONCAT('Market ', NEW.status, ': ', NEW.name), 'market', NEW.id);
  END IF;
END$$
DELIMITER ;

-- ==========================================================================
-- SEED DATA (INSERT IGNORE on unique slugs, so admin edits are never overwritten)
-- ==========================================================================

INSERT IGNORE INTO market_types (name, slug, farmer_label, icon, description, sort_order) VALUES
  ('Grain / Cereal Market',       'grain',      'Grain Farmer',       NULL, 'Wheat, rice, maize and other staple cereals.', 1),
  ('Vegetable Market',            'vegetable',  'Vegetable Farmer',   NULL, 'Fresh seasonal vegetables from local farms.', 2),
  ('Fruit Market',                'fruit',      'Fruit Farmer',       NULL, 'Fresh locally grown fruits from verified farmers.', 3),
  ('Oilseed Market',              'oilseed',    'Oilseed Farmer',     NULL, 'Sunflower, mustard, soybean and other oil crops.', 4),
  ('Pulse & Legume Market',       'pulse',      'Pulse Farmer',       NULL, 'Chickpeas, lentils, beans and other pulses.', 5),
  ('Spice & Herb Market',         'spice',      'Spice Farmer',       NULL, 'Whole spices and fresh culinary herbs.', 6),
  ('Cash Crop Market',            'cash-crop',  'Cash Crop Farmer',   NULL, 'Cotton, sugarcane, tea and other commercial crops.', 7),
  ('Flower Market',               'flower',     'Flower Grower',      NULL, 'Cut flowers for decoration, perfume and events.', 8),
  ('Forestry & Tree Market',      'forestry',   'Forestry Farmer',    NULL, 'Timber and tree species for planting and wood.', 9),
  ('Nursery & Plant Market',      'nursery',    'Nursery Farmer',     NULL, 'Seedlings, saplings and potted plants.', 10),
  ('Livestock Market',            'livestock',  'Livestock Farmer',   NULL, 'Cattle, buffalo, goats, sheep and camels.', 11),
  ('Poultry Market',              'poultry',    'Poultry Farmer',     NULL, 'Broilers, layers, ducks, turkeys and quails.', 12),
  ('Fish & Aquaculture Market',   'fish',       'Fish Farmer',        NULL, 'Farmed fish and shrimp.', 13),
  ('Honey & Beekeeping Market',   'honey',      'Beekeeper',          NULL, 'Honey and other hive products.', 14),
  ('Medicinal Plant Market',      'medicinal',  'Medicinal Plant Farmer', NULL, 'Herbs and plants grown for traditional medicine.', 15);

-- Top-level product categories (existing Vegetables / Fruits / Herbs & Spices rows are reused).
INSERT IGNORE INTO categories (name, slug, sort_order) VALUES
  ('Grains & Cereals',  'grains-cereals', 10),
  ('Oilseeds',          'oilseeds', 11),
  ('Pulses & Legumes',  'pulses-legumes', 12),
  ('Cash Crops',        'cash-crops', 13),
  ('Flowers',           'flowers', 14),
  ('Trees & Timber',    'trees-timber', 15),
  ('Plants',            'plants', 16),
  ('Cattle',            'cattle', 17),
  ('Buffalo',           'buffalo', 18),
  ('Goat',              'goat', 19),
  ('Sheep',             'sheep', 20),
  ('Camel',             'camel', 21),
  ('Poultry',           'poultry', 22),
  ('Fish & Seafood',    'fish-seafood', 23),
  ('Bee Products',      'bee-products', 24),
  ('Medicinal Plants',  'medicinal-plants', 25);

-- Link categories to market types (only where not yet linked, so admin changes stick).
UPDATE categories c JOIN market_types t ON t.slug = CASE c.slug
    WHEN 'vegetables'       THEN 'vegetable'
    WHEN 'fruits'           THEN 'fruit'
    WHEN 'herbs-spices'     THEN 'spice'
    WHEN 'grains-cereals'   THEN 'grain'
    WHEN 'oilseeds'         THEN 'oilseed'
    WHEN 'pulses-legumes'   THEN 'pulse'
    WHEN 'cash-crops'       THEN 'cash-crop'
    WHEN 'flowers'          THEN 'flower'
    WHEN 'trees-timber'     THEN 'forestry'
    WHEN 'plants'           THEN 'nursery'
    WHEN 'cattle'           THEN 'livestock'
    WHEN 'buffalo'          THEN 'livestock'
    WHEN 'goat'             THEN 'livestock'
    WHEN 'sheep'            THEN 'livestock'
    WHEN 'camel'            THEN 'livestock'
    WHEN 'poultry'          THEN 'poultry'
    WHEN 'fish-seafood'     THEN 'fish'
    WHEN 'bee-products'     THEN 'honey'
    WHEN 'medicinal-plants' THEN 'medicinal'
  END
   SET c.market_type_id = t.id
 WHERE c.market_type_id IS NULL AND c.parent_id IS NULL;

-- Subcategories: (parent slug, name, slug)
DROP TEMPORARY TABLE IF EXISTS _seed_sub;
CREATE TEMPORARY TABLE _seed_sub (parent VARCHAR(80), name VARCHAR(80), slug VARCHAR(80), sort_order INT) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO _seed_sub VALUES
  ('plants','Fruit Plants','plants-fruit',1), ('plants','Vegetable Seedlings','plants-vegetable-seedlings',2),
  ('plants','Flower Plants','plants-flower',3), ('plants','Indoor Plants','plants-indoor',4),
  ('plants','Outdoor Plants','plants-outdoor',5), ('plants','Decorative Plants','plants-decorative',6),
  ('plants','Medicinal Plants','plants-medicinal',7), ('plants','Tree Seedlings','plants-tree-seedlings',8),
  ('cattle','Cows','cattle-cows',1), ('cattle','Bulls','cattle-bulls',2), ('cattle','Calves','cattle-calves',3),
  ('buffalo','Milk Production','buffalo-milk',1), ('buffalo','Meat Production','buffalo-meat',2),
  ('goat','Milk','goat-milk',1), ('goat','Meat','goat-meat',2),
  ('sheep','Meat','sheep-meat',1), ('sheep','Wool','sheep-wool',2),
  ('camel','Milk','camel-milk',1), ('camel','Transport','camel-transport',2), ('camel','Meat','camel-meat',3),
  ('poultry','Chickens','poultry-chickens',1), ('poultry','Broilers','poultry-broilers',2), ('poultry','Layers','poultry-layers',3),
  ('poultry','Ducks','poultry-ducks',4), ('poultry','Turkeys','poultry-turkeys',5), ('poultry','Quails','poultry-quails',6),
  ('flowers','Cut Flowers','flowers-cut',1), ('flowers','Decoration','flowers-decoration',2),
  ('flowers','Perfume','flowers-perfume',3), ('flowers','Events','flowers-events',4), ('flowers','Nursery Flowers','flowers-nursery',5);
INSERT IGNORE INTO categories (market_type_id, parent_id, name, slug, sort_order)
  SELECT p.market_type_id, p.id, s.name, s.slug, s.sort_order
    FROM _seed_sub s JOIN categories p ON p.slug = s.parent;
DROP TEMPORARY TABLE _seed_sub;

-- Suggested products: (category slug, name)
DROP TEMPORARY TABLE IF EXISTS _seed_cat;
CREATE TEMPORARY TABLE _seed_cat (cat VARCHAR(80), name VARCHAR(120)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO _seed_cat VALUES
  ('grains-cereals','Wheat'),('grains-cereals','Rice'),('grains-cereals','Maize/Corn'),('grains-cereals','Barley'),
  ('grains-cereals','Oats'),('grains-cereals','Sorghum/Jowar'),('grains-cereals','Millet/Bajra'),('grains-cereals','Rye'),
  ('vegetables','Potato'),('vegetables','Onion'),('vegetables','Tomato'),('vegetables','Carrot'),('vegetables','Radish'),
  ('vegetables','Turnip'),('vegetables','Cabbage'),('vegetables','Cauliflower'),('vegetables','Spinach'),('vegetables','Peas'),
  ('vegetables','Okra/Bhindi'),('vegetables','Brinjal/Eggplant'),('vegetables','Cucumber'),('vegetables','Pumpkin'),
  ('vegetables','Bitter Gourd'),('vegetables','Bottle Gourd'),('vegetables','Capsicum'),('vegetables','Green Chilli'),
  ('vegetables','Garlic'),('vegetables','Ginger'),
  ('fruits','Mango'),('fruits','Apple'),('fruits','Banana'),('fruits','Orange/Kinnow'),('fruits','Guava'),('fruits','Papaya'),
  ('fruits','Pomegranate'),('fruits','Grapes'),('fruits','Watermelon'),('fruits','Melon'),('fruits','Peach'),('fruits','Plum'),
  ('fruits','Apricot'),('fruits','Pear'),('fruits','Dates'),('fruits','Lychee'),('fruits','Strawberry'),('fruits','Coconut'),
  ('oilseeds','Sunflower'),('oilseeds','Mustard/Sarson'),('oilseeds','Soybean'),('oilseeds','Groundnut/Peanut'),
  ('oilseeds','Sesame/Til'),('oilseeds','Canola'),('oilseeds','Flaxseed'),
  ('pulses-legumes','Chickpeas/Chana'),('pulses-legumes','Lentils/Masoor'),('pulses-legumes','Kidney Beans/Rajma'),
  ('pulses-legumes','Black Gram/Mash'),('pulses-legumes','Mung Beans/Moong'),('pulses-legumes','Peas'),
  ('pulses-legumes','Cowpeas/Lobia'),('pulses-legumes','Pigeon Pea/Arhar'),
  ('herbs-spices','Coriander/Dhaniya'),('herbs-spices','Cumin/Zeera'),('herbs-spices','Turmeric/Haldi'),
  ('herbs-spices','Ginger/Adrak'),('herbs-spices','Garlic/Lehsan'),('herbs-spices','Black Pepper'),('herbs-spices','Cardamom'),
  ('herbs-spices','Cloves'),('herbs-spices','Cinnamon'),('herbs-spices','Mint/Pudina'),('herbs-spices','Basil'),
  ('herbs-spices','Fennel/Saunf'),('herbs-spices','Fenugreek/Methi'),
  ('cash-crops','Cotton'),('cash-crops','Sugarcane'),('cash-crops','Tobacco'),('cash-crops','Tea'),('cash-crops','Coffee'),
  ('cash-crops','Rubber'),('cash-crops','Cocoa'),('cash-crops','Jute'),
  ('flowers','Rose'),('flowers','Jasmine'),('flowers','Marigold'),('flowers','Sunflower'),('flowers','Tulip'),('flowers','Lily'),
  ('flowers','Lavender'),('flowers','Chrysanthemum'),('flowers','Orchid'),
  ('trees-timber','Eucalyptus'),('trees-timber','Neem'),('trees-timber','Acacia'),('trees-timber','Pine'),('trees-timber','Poplar'),
  ('trees-timber','Sheesham'),('trees-timber','Bamboo'),('trees-timber','Teak'),
  ('poultry','Chicken'),('poultry','Broiler'),('poultry','Layer'),('poultry','Duck'),('poultry','Turkey'),('poultry','Quail'),
  ('fish-seafood','Rohu'),('fish-seafood','Catla'),('fish-seafood','Tilapia'),('fish-seafood','Trout'),('fish-seafood','Carp'),
  ('fish-seafood','Catfish'),('fish-seafood','Prawns/Shrimp'),
  ('bee-products','Honey'),('bee-products','Beeswax'),('bee-products','Royal Jelly'),('bee-products','Propolis'),
  ('medicinal-plants','Aloe Vera'),('medicinal-plants','Neem'),('medicinal-plants','Ashwagandha'),('medicinal-plants','Moringa'),
  ('medicinal-plants','Senna'),('medicinal-plants','Tulsi'),('medicinal-plants','Mint'),('medicinal-plants','Lemongrass'),
  ('medicinal-plants','Isabgol/Psyllium'),
  ('cattle','Cow'),('cattle','Bull'),('cattle','Calf'),('buffalo','Buffalo'),('goat','Goat'),('sheep','Sheep'),('camel','Camel');
INSERT IGNORE INTO catalog_products (category_id, name)
  SELECT c.id, s.name FROM _seed_cat s JOIN categories c ON c.slug = s.cat;
DROP TEMPORARY TABLE _seed_cat;
