<?php
declare(strict_types=1);

/**
 * Central admin config bootstrap for LRO File Upload.
 * - Uses PrestaShop's own DB config (no hard-coded credentials).
 * - Provides $db (Db::getInstance()) and an optional $pdo (PDO) for legacy code.
 * - Exposes $prefix = _DB_PREFIX_ and helper lro_tbl('table_name').
 */
require_once __DIR__ . '/session_bootstrap.php'; // boots PS config + init

// Presta DB handle (preferred)
$db = Db::getInstance();
$prefix = _DB_PREFIX_;

// Optional PDO handle for code that still uses PDO
if (!isset($pdo) || !($pdo instanceof PDO)) {
    try {
        $dsn = 'mysql:host=' . _DB_SERVER_ . ';dbname=' . _DB_NAME_ . ';charset=utf8mb4';
        $pdo = new PDO($dsn, _DB_USER_, _DB_PASSWD_, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (Throwable $e) {
        // Keep the message generic to avoid leaking details
        die('Database bootstrap failed.');
    }
}

// Small helper for prefixed table names, e.g. lro_tbl('lrofileupload_uploads')
if (!function_exists('lro_tbl')) {
    function lro_tbl(string $name): string {
        return _DB_PREFIX_ . $name;
    }
}
