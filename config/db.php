<?php
/**
 * Robust DB bootstrap for LRO module.
 * Finds PrestaShop config (settings.inc.php or parameters.php),
 * defines DB constants if missing, and creates $pdo.
 */

declare(strict_types=1);

if (!defined('LRO_DB_BOOTSTRAPPED')) {
    define('LRO_DB_BOOTSTRAPPED', true);

    // ---------- find shop root
    $here = __DIR__;
    $candidates = [
        // typical PS roots relative to this file
        $here . '/../../config/settings.inc.php',                // PS 1.6/1.7
        $here . '/../../../config/settings.inc.php',
    ];

    $settingsPath = null;
    foreach ($candidates as $c) {
        if (is_file($c)) {
            $settingsPath = realpath($c);
            break;
        }
    }

    // ---------- include settings.inc.php if present
    if ($settingsPath) {
        require_once $settingsPath;
    }

    // ---------- If DB constants are still missing, try parameters.php (PS 1.7/8)
    $needDbConsts = !defined('_DB_SERVER_') || !defined('_DB_NAME_') || !defined('_DB_USER_');

    if ($needDbConsts) {
        $root = dirname(dirname($settingsPath ?: $here)); // best guess
        $paramsCandidates = [
            $root . '/app/config/parameters.php', // PS 1.7
            $root . '/config/parameters.php',     // PS 8
        ];
        foreach ($paramsCandidates as $p) {
            if (is_file($p)) {
                $parameters = include $p; // returns array
                if (is_array($parameters) && isset($parameters['parameters'])) {
                    $pp = $parameters['parameters'];
                    if (!defined('_DB_SERVER_') && isset($pp['database_host'])) {
                        define('_DB_SERVER_', (string) $pp['database_host']);
                    }
                    if (!defined('_DB_NAME_') && isset($pp['database_name'])) {
                        define('_DB_NAME_', (string) $pp['database_name']);
                    }
                    if (!defined('_DB_USER_') && isset($pp['database_user'])) {
                        define('_DB_USER_', (string) $pp['database_user']);
                    }
                    if (!defined('_DB_PASSWD_') && isset($pp['database_password'])) {
                        define('_DB_PASSWD_', (string) $pp['database_password']);
                    }
                    if (!defined('_DB_PREFIX_') && isset($pp['database_prefix'])) {
                        define('_DB_PREFIX_', (string) $pp['database_prefix']);
                    }
                    break;
                }
            }
        }
    }

    // ---------- final fallback: environment variables (optional)
    if (!defined('_DB_SERVER_') && getenv('DB_HOST')) {
        define('_DB_SERVER_', getenv('DB_HOST'));
    }
    if (!defined('_DB_NAME_') && getenv('DB_NAME')) {
        define('_DB_NAME_', getenv('DB_NAME'));
    }
    if (!defined('_DB_USER_') && getenv('DB_USER')) {
        define('_DB_USER_', getenv('DB_USER'));
    }
    if (!defined('_DB_PASSWD_') && getenv('DB_PASS')) {
        define('_DB_PASSWD_', getenv('DB_PASS'));
    }
    if (!defined('_DB_PREFIX_') && getenv('DB_PREFIX')) {
        define('_DB_PREFIX_', getenv('DB_PREFIX'));
    }

    // ---------- sanity check (no debug echo)
    if (!defined('_DB_SERVER_') || !defined('_DB_NAME_') || !defined('_DB_USER_')) {
        // Fail hard but quietly (no browser output)
        throw new RuntimeException('LRO DB bootstrap: database constants are missing.');
    }

    // Prefix default
    if (!defined('_DB_PREFIX_')) {
        define('_DB_PREFIX_', 'ps_');
    }

    // ---------- make PDO
    try {
        $dsn  = 'mysql:host=' . _DB_SERVER_ . ';dbname=' . _DB_NAME_ . ';charset=utf8mb4';
        $user = _DB_USER_;
        $pass = defined('_DB_PASSWD_') ? _DB_PASSWD_ : '';

        /** @var PDO $pdo */
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (Throwable $e) {
        // Log to PHP error log, but no direct output
        if (function_exists('error_log')) {
            error_log('LRO DB bootstrap connection failed: ' . $e->getMessage());
        }
        throw $e;
    }
}
