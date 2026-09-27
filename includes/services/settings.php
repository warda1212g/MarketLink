<?php
/**
 * Site settings from Admin -> Settings (saved in app_settings).
 */
declare(strict_types=1);

/** Defaults for every setting the admin can change. */
const APP_SETTING_DEFAULTS = [
    'registration.customers'  => '1',  // allow new customer sign-ups
    'registration.vendors'    => '1',  // allow new farmer / vendor sign-ups
    'vendors.auto_approve'    => '0',  // approve new farmer accounts without admin review
    'announcement.enabled'    => '0',  // show a banner across the customer website
    'announcement.text'       => '',
    'announcement.tone'       => 'info', // info | success | warning
];

function app_settings_ensure_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec("CREATE TABLE IF NOT EXISTS app_settings (
      setting_key   VARCHAR(80)  NOT NULL PRIMARY KEY,
      setting_value TEXT         NULL,
      updated_by    INT UNSIGNED NULL,
      updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

/** All settings (stored values over defaults), loaded once per request. */
function app_settings(bool $refresh = false): array
{
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = APP_SETTING_DEFAULTS;
        try {
            app_settings_ensure_table();
            foreach (db_all('SELECT setting_key, setting_value FROM app_settings') as $r) {
                $cache[$r['setting_key']] = (string) $r['setting_value'];
            }
        } catch (Throwable $e) {
            error_log('[MarketLink settings] ' . $e->getMessage()); // fall back to defaults
        }
    }
    return $cache;
}

function app_setting(string $key): string
{
    return app_settings()[$key] ?? (APP_SETTING_DEFAULTS[$key] ?? '');
}

function app_setting_on(string $key): bool
{
    return app_setting($key) === '1';
}

/** Saves several settings at once. Unknown keys are ignored. */
function app_settings_save(array $values, int $adminId): void
{
    app_settings_ensure_table();
    $stmt = db()->prepare('INSERT INTO app_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)');
    foreach ($values as $key => $value) {
        if (array_key_exists($key, APP_SETTING_DEFAULTS)) {
            $stmt->execute([$key, (string) $value, $adminId]);
        }
    }
    app_settings(true);
}

/** Banner markup for the customer website, or '' when the announcement is off. */
function site_announcement_html(): string
{
    $text = trim(app_setting('announcement.text'));
    if (!app_setting_on('announcement.enabled') || $text === '') {
        return '';
    }
    $tone = in_array(app_setting('announcement.tone'), ['info', 'success', 'warning'], true) ? app_setting('announcement.tone') : 'info';
    return '<div class="site-announcement site-announcement-' . $tone . '" role="status">' . e($text) . '</div>';
}
