<?php
/** Customer favourites: saved stalls (vendor) and products. */
declare(strict_types=1);

/** Creates the favorites table on first use so no manual migration is required. */
function favorites_ensure_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec("CREATE TABLE IF NOT EXISTS favorites (
      customer_id INT UNSIGNED NOT NULL,
      item_type   ENUM('vendor','product') NOT NULL,
      item_id     INT UNSIGNED NOT NULL,
      created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (customer_id, item_type, item_id),
      KEY idx_favorites_item (item_type, item_id),
      CONSTRAINT fk_favorites_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

/** ['vendor' => [id => true], 'product' => [id => true]] for quick lookups while rendering. */
function favorite_ids(int $customerId): array
{
    favorites_ensure_table();
    $out = ['vendor' => [], 'product' => []];
    foreach (db_all('SELECT item_type, item_id FROM favorites WHERE customer_id = ?', [$customerId]) as $r) {
        $out[$r['item_type']][(int) $r['item_id']] = true;
    }
    return $out;
}

/** Toggles a favourite; returns true when it is now saved. */
function favorite_toggle(int $customerId, string $type, int $id): bool
{
    favorites_ensure_table();
    $params = [$customerId, $type, $id];
    if (db_value('SELECT 1 FROM favorites WHERE customer_id = ? AND item_type = ? AND item_id = ?', $params)) {
        db_query('DELETE FROM favorites WHERE customer_id = ? AND item_type = ? AND item_id = ?', $params);
        return false;
    }
    db_query('INSERT INTO favorites (customer_id, item_type, item_id) VALUES (?, ?, ?)', $params);
    return true;
}

/** Heart button markup; data attributes are picked up by assets/js/favorites.js. */
function favorite_button(string $type, int $id, bool $active, string $name): string
{
    $label = ($active ? 'Remove ' : 'Save ') . $name . ($active ? ' from favourites' : ' to favourites');
    return '<button type="button" class="fav-toggle' . ($active ? ' active' : '') . '" data-fav-type="' . e($type) . '" data-fav-id="' . $id . '"'
        . ' aria-pressed="' . ($active ? 'true' : 'false') . '" aria-label="' . e($label) . '" title="' . e($label) . '"><svg><use href="#i-heart"/></svg></button>';
}

/**
 * Heart button. Guests are sent to login, vendors and admins don't see it.
 */
function favorite_heart_for_viewer(string $type, int $id, string $name, string $returnTo): string
{
    static $favs = null;
    $user = current_user();
    if ($user && $user['role'] === 'customer') {
        $favs ??= favorite_ids((int) $user['id']);
        return favorite_button($type, $id, isset($favs[$type][$id]), $name);
    }
    if ($user) {
        return '';
    }
    return str_replace('<button ', '<button data-login="' . e(url('auth/login.php?next=' . urlencode($returnTo))) . '" ', favorite_button($type, $id, false, $name));
}

/** Labelled version ("Save stall" / "Saved") for places where a bare heart is easy to miss. */
function favorite_save_button(string $type, int $id, bool $active, string $name, string $noun = 'stall'): string
{
    $label = ($active ? 'Remove ' : 'Save ') . $name . ($active ? ' from favourites' : ' to favourites');
    return '<button type="button" class="btn btn-ghost btn-sm fav-save' . ($active ? ' active' : '') . '" data-fav-type="' . e($type) . '" data-fav-id="' . $id . '"'
        . ' data-fav-on="Saved" data-fav-off="Save ' . e($noun) . '"'
        . ' aria-pressed="' . ($active ? 'true' : 'false') . '" aria-label="' . e($label) . '"><svg><use href="#i-heart"/></svg><span data-fav-text>' . ($active ? 'Saved' : 'Save ' . e($noun)) . '</span></button>';
}
