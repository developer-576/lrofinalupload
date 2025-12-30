<?php
// modules/lrofileupload/ps_config.php
declare(strict_types=1);

/**
 * PrestaShop-aware config bootstrap for the LRO module.
 * - NO hard-coded DB creds.
 * - Uses PrestaShop's own config (env-safe).
 * - Exposes: $db (Db::getInstance()), $pdo (PDO), $prefix, lro_tbl().
 */

// Error reporting: verbose in dev, quieter in prod
if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}

// Bootstrap PrestaShop from module path: /modules/lrofileupload/ -> PS root is two levels up
$psRoot = dirname(__FILE__, 2);
if (!is_dir($psRoot)) {
    die('PrestaShop root not found.');
}

require_once $psRoot . '/config/config.inc.php';
require_once $psRoot . '/init.php';

// Preferred: PrestaShop DB instance
$db = Db::getInstance();

// Convenience
$prefix = _DB_PREFIX_;

// Optional: PDO handle for any legacy code that still expects PDO
if (!isset($pdo) || !($pdo instanceof PDO)) {
    try {
        $dsn = 'mysql:host=' . _DB_SERVER_ . ';dbname=' . _DB_NAME_ . ';charset=utf8mb4';
        $pdo = new PDO($dsn, _DB_USER_, _DB_PASSWD_, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (Throwable $e) {
        // Keep this generic to avoid leaking env details
        die('Database bootstrap failed.');
    }
}

/**
 * Helper for prefixed table names, e.g. lro_tbl('lrofileupload_uploads')
 */
if (!function_exists('lro_tbl')) {
    function lro_tbl(string $name): string {
        return _DB_PREFIX_ . $name;
    }
}
