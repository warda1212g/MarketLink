<?php
/**
 * Loaded by every PHP entry point. Sets up config, errors, session and helpers.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$GLOBALS['__config'] = require APP_ROOT . '/config/config.php';

function config(string $key, $default = null)
{
    $value = $GLOBALS['__config'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

date_default_timezone_set(config('app.timezone', 'UTC'));

if (config('app.debug')) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');

require_once APP_ROOT . '/includes/db.php';
require_once APP_ROOT . '/includes/helpers.php';
require_once APP_ROOT . '/includes/services/settings.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/admin.php';

start_secure_session();
