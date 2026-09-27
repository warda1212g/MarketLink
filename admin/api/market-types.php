<?php
/**
 * Admin API for market types, product categories/subcategories and the suggested product catalogue.
 * Admin session required; every POST needs X-CSRF-Token.
 *
 * GET  ?action=tree                                     -> types with categories, subcategories and catalogue
 * POST action=type_save {id?, name, farmer_label, icon, description, sort_order, is_active}
 * POST action=type_toggle {id}
 * POST action=type_delete {id}                          -> blocked while markets use the type
 * POST action=category_save {id?, name, market_type_id?, parent_id?, description}
 * POST action=category_toggle {id}
 * POST action=category_delete {id}                      -> blocked while products use it
 * POST action=catalog_add {category_id, name}
 * POST action=catalog_delete {id}
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once APP_ROOT . '/includes/services/markets.php';

$admin = require_admin_api();

api_run(function () use ($admin) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (($_GET['action'] ?? '') === 'detail') {
            json_ok(type_detail(int_input($_GET, 'id', 0)));
        }
        json_ok(['types' => types_tree()]);
    }

    require_method('POST');
    $in = request_data();
    $adminId = (int) $admin['id'];

    switch ($in['action'] ?? '') {
        case 'type_save':
            $id = int_input($in, 'id');
            if ($id && !db_value('SELECT 1 FROM market_types WHERE id = ?', [$id])) {
                json_error('Market type not found.', 404);
            }
            $d = [
                'name'         => preg_replace('/\s+/', ' ', str_input($in, 'name', 80)),
                'farmer_label' => preg_replace('/\s+/', ' ', str_input($in, 'farmer_label', 80)),
                'icon'         => str_input($in, 'icon', 16),
                'description'  => str_input($in, 'description', 500),
                'sort_order'   => int_input($in, 'sort_order', 0),
                'is_active'    => empty($in['is_active']) ? 0 : 1,
            ];
            $e = [];
            if (mb_strlen($d['name']) < 3) $e['name'] = 'Enter a name of at least 3 characters.';
            elseif (db_value('SELECT 1 FROM market_types WHERE name = ?' . ($id ? ' AND id <> ?' : ''), $id ? [$d['name'], $id] : [$d['name']])) $e['name'] = 'A market type with this name already exists.';
            if ($d['farmer_label'] === '') {
                $d['farmer_label'] = mb_substr(trim(preg_replace('/\\s*market$/i', '', $d['name'])) . ' Vendor', 0, 80);
            }
            if ($d['sort_order'] < 0 || $d['sort_order'] > 9999) $e['sort_order'] = 'Use a number from 0 to 9999.';
            if ($e) throw new ValidationException($e);
            $image = store_uploaded_image('image', 'market-categories');
            $old = $id ? db_value('SELECT image FROM market_types WHERE id = ?', [$id]) : null;
            if ($image || !empty($in['remove_image'])) {
                delete_upload($old);
            }

            if ($id) {
                db_query('UPDATE market_types SET name=?, farmer_label=?, icon=?, image=?, description=?, sort_order=?, is_active=? WHERE id=?',
                    [$d['name'], $d['farmer_label'], $d['icon'] ?: null, $image ?: (!empty($in['remove_image']) ? null : $old), $d['description'] ?: null, $d['sort_order'], $d['is_active'], $id]);
                log_activity('market_type_updated', 'Market type updated: ' . $d['name'], 'market_type', $id, $adminId);
            } else {
                db_query('INSERT INTO market_types (name, slug, farmer_label, icon, image, description, sort_order, is_active) VALUES (?,?,?,?,?,?,?,?)',
                    [$d['name'], unique_slug('market_types', slugify($d['name'])), $d['farmer_label'], $d['icon'] ?: null, $image, $d['description'] ?: null, $d['sort_order'], $d['is_active']]);
                $id = (int) db()->lastInsertId();
                log_activity('market_type_created', 'Market type created: ' . $d['name'], 'market_type', $id, $adminId);
            }
            json_ok(['id' => $id], 'Market category saved.');

        case 'type_toggle':
            $t = db_one('SELECT id, name, is_active FROM market_types WHERE id = ?', [int_input($in, 'id', 0)]);
            if (!$t) json_error('Market type not found.', 404);
            db_query('UPDATE market_types SET is_active = 1 - is_active WHERE id = ?', [$t['id']]);
            json_ok([], $t['name'] . ($t['is_active'] ? ' deactivated.' : ' activated.'));

        case 'type_delete':
            $t = db_one('SELECT id, name FROM market_types WHERE id = ?', [int_input($in, 'id', 0)]);
            if (!$t) json_error('Market type not found.', 404);
            $n = (int) db_value('SELECT COUNT(*) FROM markets WHERE market_type_id = ?', [$t['id']]);
            if ($n) json_error("$n market location(s) use this category. Deactivate it instead, or move those markets first.", 409);
            $n = (int) db_value('SELECT COUNT(*) FROM farmer_categories WHERE market_type_id = ?', [$t['id']]);
            if ($n) json_error("$n vendor stall(s) are in this category. Deactivate it instead so their stalls keep their history.", 409);
            // categories keep existing (market_type_id -> NULL) so products are never orphaned
            db_query('DELETE FROM market_types WHERE id = ?', [$t['id']]);
            log_activity('market_type_deleted', 'Market type deleted: ' . $t['name'], 'market_type', (int) $t['id'], $adminId);
            json_ok([], 'Market type deleted.');

        case 'category_save':
            $id = int_input($in, 'id');
            $existing = $id ? db_one('SELECT * FROM categories WHERE id = ?', [$id]) : null;
            if ($id && !$existing) json_error('Category not found.', 404);
            $name = preg_replace('/\s+/', ' ', str_input($in, 'name', 80));
            $parentId = $existing ? ($existing['parent_id'] !== null ? (int) $existing['parent_id'] : null) : int_input($in, 'parent_id');
            $typeId = int_input($in, 'market_type_id');
            $e = [];
            if (mb_strlen($name) < 2) $e['name'] = 'Enter a category name.';
            if ($parentId) {
                $parent = db_one('SELECT id, market_type_id, parent_id FROM categories WHERE id = ?', [$parentId]);
                if (!$parent || $parent['parent_id'] !== null) $e['parent_id'] = 'Subcategories can only sit under a top-level category.';
                $typeId = $parent ? ($parent['market_type_id'] !== null ? (int) $parent['market_type_id'] : null) : null;
            } elseif ($typeId && !db_value('SELECT 1 FROM market_types WHERE id = ?', [$typeId])) {
                $e['market_type_id'] = 'Choose a valid market type.';
            }
            if (!$e && db_value('SELECT 1 FROM categories WHERE name = ? AND parent_id <=> ? AND market_type_id <=> ?' . ($id ? ' AND id <> ?' : ''),
                    array_merge([$name, $parentId, $typeId], $id ? [$id] : []))) {
                $e['name'] = 'This category already exists here.';
            }
            if ($e) throw new ValidationException($e);

            if ($existing) {
                db_query('UPDATE categories SET name = ?, market_type_id = ?, description = ? WHERE id = ?',
                    [$name, $typeId, str_input($in, 'description', 255) ?: null, $id]);
                if ($existing['parent_id'] === null) {
                    // subcategories follow their parent's market type
                    db_query('UPDATE categories SET market_type_id = ? WHERE parent_id = ?', [$typeId, $id]);
                }
            } else {
                $sort = (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM categories WHERE parent_id <=> ?', [$parentId]);
                db_query('INSERT INTO categories (market_type_id, parent_id, name, slug, description, sort_order) VALUES (?,?,?,?,?,?)',
                    [$typeId, $parentId, $name, unique_slug('categories', slugify($name)), str_input($in, 'description', 255) ?: null, $sort]);
                $id = (int) db()->lastInsertId();
            }
            json_ok(['id' => $id], $parentId ? 'Subcategory saved.' : 'Category saved.');

        case 'category_toggle':
            $c = db_one('SELECT id, name, is_active FROM categories WHERE id = ?', [int_input($in, 'id', 0)]);
            if (!$c) json_error('Category not found.', 404);
            db_query('UPDATE categories SET is_active = 1 - is_active WHERE id = ?', [$c['id']]);
            json_ok([], $c['name'] . ($c['is_active'] ? ' hidden from farmers.' : ' visible to farmers.'));

        case 'category_delete':
            $c = db_one('SELECT id, name FROM categories WHERE id = ?', [int_input($in, 'id', 0)]);
            if (!$c) json_error('Category not found.', 404);
            $n = (int) db_value(
                'SELECT COUNT(*) FROM products WHERE category_id = ? OR subcategory_id = ?
                    OR subcategory_id IN (SELECT id FROM categories WHERE parent_id = ?)
                    OR category_id IN (SELECT id FROM categories WHERE parent_id = ?)',
                [$c['id'], $c['id'], $c['id'], $c['id']]
            );
            if ($n) json_error("$n product(s) use this category. Hide it instead so their history is kept.", 409);
            db_query('DELETE FROM categories WHERE id = ?', [$c['id']]); // subcategories + catalogue cascade
            json_ok([], 'Category deleted.');

        case 'catalog_add':
            $catId = int_input($in, 'category_id', 0);
            $name = preg_replace('/\s+/', ' ', str_input($in, 'name', 120));
            if (!db_value('SELECT 1 FROM categories WHERE id = ?', [$catId])) json_error('Category not found.', 404);
            if (mb_strlen($name) < 2) throw new ValidationException(['name' => 'Enter a product name.'], 'Enter a product name.');
            db_query('INSERT IGNORE INTO catalog_products (category_id, name) VALUES (?, ?)', [$catId, $name]);
            json_ok(['id' => (int) db_value('SELECT id FROM catalog_products WHERE category_id = ? AND name = ?', [$catId, $name])], "$name added.");

        case 'catalog_delete':
            db_query('DELETE FROM catalog_products WHERE id = ?', [int_input($in, 'id', 0)]);
            json_ok([], 'Removed.');

        default:
            json_error('Unknown action.');
    }
});

function types_tree(): array
{
    $cats = db_all(
        'SELECT c.id, c.market_type_id, c.parent_id, c.name, c.description, c.is_active,
                (SELECT COUNT(*) FROM products p WHERE (p.category_id = c.id OR p.subcategory_id = c.id) AND p.deleted_at IS NULL) AS products
           FROM categories c ORDER BY c.sort_order, c.name'
    );
    $catalog = [];
    foreach (db_all('SELECT id, category_id, name FROM catalog_products ORDER BY sort_order, name') as $p) {
        $catalog[(int) $p['category_id']][] = ['id' => (int) $p['id'], 'name' => $p['name']];
    }
    $byId = [];
    foreach ($cats as $c) {
        $byId[(int) $c['id']] = [
            'id' => (int) $c['id'], 'name' => $c['name'], 'description' => $c['description'],
            'is_active' => (bool) $c['is_active'], 'products' => (int) $c['products'],
            'market_type_id' => $c['market_type_id'] !== null ? (int) $c['market_type_id'] : null,
            'parent_id' => $c['parent_id'] !== null ? (int) $c['parent_id'] : null,
            'catalog' => $catalog[(int) $c['id']] ?? [], 'children' => [],
        ];
    }
    foreach ($byId as $id => $c) {
        if ($c['parent_id'] && isset($byId[$c['parent_id']])) {
            $byId[$c['parent_id']]['children'][] = $c;
        }
    }
    $types = array_map(fn($t) => [
        'id' => (int) $t['id'], 'name' => $t['name'], 'slug' => $t['slug'], 'farmer_label' => $t['farmer_label'],
        'icon' => $t['icon'], 'image' => upload_url($t['image'] ?? null), 'description' => $t['description'], 'sort_order' => (int) $t['sort_order'],
        'is_active' => (bool) $t['is_active'], 'markets' => (int) $t['markets'],
        'farmers' => (int) db_value('SELECT COUNT(*) FROM farmer_categories WHERE market_type_id = ?', [$t['id']]),
        'categories' => [],
    ], market_types_all());
    $typeIdx = array_flip(array_column($types, 'id'));
    $general = [];
    foreach ($byId as $c) {
        if ($c['parent_id']) continue;
        if ($c['market_type_id'] !== null && isset($typeIdx[$c['market_type_id']])) {
            $types[$typeIdx[$c['market_type_id']]]['categories'][] = $c;
        } else {
            $general[] = $c;
        }
    }
    $types[] = ['id' => 0, 'name' => 'General categories', 'slug' => 'general', 'farmer_label' => '', 'icon' => '',
        'description' => 'Not tied to a market type; available to every farmer.', 'sort_order' => 99999, 'is_active' => true,
        'markets' => 0, 'farmers' => 0, 'categories' => $general, 'general' => true];
    return $types;
}

/** Vendors/stalls and products that belong to one market category (for the admin "View" dialog). */
function type_detail(int $id): array
{
    $t = db_one('SELECT id, name, description, is_active FROM market_types WHERE id = ?', [$id]);
    if (!$t) {
        json_error('Market category not found.', 404);
    }
    $vendors = db_all(
        'SELECT v.id, v.stall_name, u.name AS owner, u.approval_status,
                (SELECT COUNT(*) FROM products p WHERE p.vendor_id = v.id AND p.deleted_at IS NULL) AS products
           FROM farmer_categories fc JOIN vendors v ON v.id = fc.vendor_id JOIN users u ON u.id = v.user_id
          WHERE fc.market_type_id = ? ORDER BY v.stall_name LIMIT 200', [$id]);
    $products = db_all(
        'SELECT p.id, p.name, p.price, p.unit, p.moderation_status, v.stall_name
           FROM products p JOIN vendors v ON v.id = p.vendor_id
           LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN categories sc ON sc.id = p.subcategory_id
          WHERE p.deleted_at IS NULL AND (c.market_type_id = ? OR sc.market_type_id = ?
                OR p.vendor_id IN (SELECT vendor_id FROM farmer_categories WHERE market_type_id = ?))
          ORDER BY p.created_at DESC LIMIT 200', [$id, $id, $id]);
    return [
        'type' => ['id' => (int) $t['id'], 'name' => $t['name'], 'description' => $t['description'], 'is_active' => (bool) $t['is_active']],
        'vendors' => array_map(fn($v) => ['id' => (int) $v['id'], 'name' => $v['stall_name'], 'owner' => $v['owner'], 'status' => $v['approval_status'],
            'products' => (int) $v['products'], 'view' => url('market.php?id=' . $v['id'])], $vendors),
        'products' => array_map(fn($p) => ['id' => (int) $p['id'], 'name' => $p['name'], 'price' => money($p['price']) . ' / ' . $p['unit'],
            'vendor' => $p['stall_name'], 'status' => $p['moderation_status']], $products),
    ];
}
