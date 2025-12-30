<?php
// modules/lrofileupload/admin/_bootstrap.php
declare(strict_types=1);

/**
 * Admin bootstrap for LRO module.
 * Boots PrestaShop, defines helpers, and provides auth gates.
 */

// You can keep these on for now; they don't produce "debug output" themselves.
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

/**
 * --------------------------------------------------------------------------
 * 1) Locate PrestaShop root and bootstrap the shop
 *    From /modules/lrofileupload/admin -> root is 3 levels up.
 *    We also walk up a few parents just in case.
 * --------------------------------------------------------------------------
 */
if (!defined('LRO_PS_BOOTSTRAPPED')) {
    $candidates = [];

    // Expected root: 3 levels up from this admin folder
    $hard = realpath(__DIR__ . '/../../../'); // -> e.g. /home/.../public_html
    if ($hard) {
        $candidates[] = $hard;
    }

    // Safety net: walk up to find /config/config.inc.php
    $dir = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        $dir = dirname($dir);
        if ($dir && !in_array($dir, $candidates, true)) {
            $candidates[] = $dir;
        }
    }

    // Deduplicate & normalize
    $candidates = array_values(array_unique(array_filter(array_map(
        static function ($p) {
            $rp = realpath($p);
            return is_string($rp) ? rtrim($rp, DIRECTORY_SEPARATOR) : null;
        },
        $candidates
    ))));

    $booted = false;
    foreach ($candidates as $root) {
        if (is_file($root . '/config/config.inc.php') && is_file($root . '/init.php')) {
            define('LRO_SHOP_ROOT', $root);
            require_once $root . '/config/config.inc.php';
            require_once $root . '/init.php';
            define('LRO_PS_BOOTSTRAPPED', true);
            $booted = true;
            break;
        }
    }

    if (!$booted) {
        // No visible debug output; just log and hard fail
        if (function_exists('error_log')) {
            error_log(
                'LRO Bootstrap error: Could not locate PrestaShop root. Candidates: ' .
                implode(', ', $candidates)
            );
        }
        http_response_code(500);
        exit;
    }
}

// Define handy module path constants (idempotent)
defined('LRO_MODULE_DIR') || define('LRO_MODULE_DIR', realpath(__DIR__ . '/..') ?: dirname(__DIR__));
defined('LRO_ADMIN_DIR')  || define('LRO_ADMIN_DIR', __DIR__);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/**
 * --------------------------------------------------------------------------
 * 2) Small utilities (HTML escape, headers, JSON, CSRF, DB, etc.)
 * --------------------------------------------------------------------------
 */

/** HTML escape helper */
if (!function_exists('lro_h')) {
    function lro_h($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

/** No-cache headers for admin/AJAX */
if (!function_exists('lro_headers_no_cache')) {
    function lro_headers_no_cache(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
}

/** JSON responder (exits) */
if (!function_exists('lro_json')) {
    function lro_json(array $payload, int $code = 200): void
    {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

/** Quick method guard */
if (!function_exists('lro_assert_method')) {
    function lro_assert_method(string $expected): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if (strcasecmp($method, $expected) !== 0) {
            lro_json(['success' => false, 'error' => "Method not allowed: expected {$expected}"], 405);
        }
    }
}

/** CSRF: get token (creates if missing) */
if (!function_exists('lro_csrf_token')) {
    function lro_csrf_token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

/** CSRF: require posted token */
if (!function_exists('lro_require_csrf')) {
    function lro_require_csrf(?string $token): void
    {
        $valid = isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string) $token);
        if (!$valid) {
            lro_json(['success' => false, 'error' => 'Invalid CSRF token'], 419);
        }
    }
}

/** DB prefix helper */
if (!function_exists('lro_prefix')) {
    function lro_prefix(): string
    {
        return defined('_DB_PREFIX_') ? (string) _DB_PREFIX_ : 'ps_';
    }
}

/** PrestaShop Db instance */
if (!function_exists('lro_db')) {
    function lro_db(): Db
    {
        return Db::getInstance();
    }
}

/**
 * PDO helper (requested for admin pages that prefer PDO).
 * Uses PrestaShop constants; caches the handle; utf8mb4; exceptions ON.
 */
if (!function_exists('lro_pdo')) {
    function lro_pdo(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }

        $host = defined('_DB_SERVER_') ? _DB_SERVER_ : 'localhost';
        $name = defined('_DB_NAME_')   ? _DB_NAME_   : '';
        $user = defined('_DB_USER_')   ? _DB_USER_   : '';
        $pass = defined('_DB_PASSWD_') ? _DB_PASSWD_ : '';
        $port = defined('_DB_PORT_')   ? (string) _DB_PORT_ : '';

        $dsn = "mysql:host={$host}" . ($port !== '' ? ";port={$port}" : "") . ";dbname={$name};charset=utf8mb4";

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_general_ci",
        ]);
        return $pdo;
    }
}

/** Table exists helper (fast) */
if (!function_exists('lro_table_exists')) {
    function lro_table_exists(string $fullTable): bool
    {
        try {
            $stmt = lro_pdo()->query(
                "SHOW TABLES LIKE " . lro_pdo()->quote($fullTable)
            );
            return (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }
}

/**
 * Minimal action logger:
 *  - If DB table `<prefix>lrofileupload_logs` exists, insert there
 *  - Otherwise, write to a flat file in the admin folder
 */
if (!function_exists('admin_log')) {
    function admin_log(string $action, array $meta = []): void
    {
        $prefix  = lro_prefix();
        $table   = $prefix . 'lrofileupload_logs';
        $adminId = $_SESSION['admin_id'] ?? null;

        $payload = [
            'time'     => date('c'),
            'action'   => $action,
            'admin_id' => $adminId,
            'ip'       => $_SERVER['REMOTE_ADDR']     ?? null,
            'ua'       => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'meta'     => $meta,
        ];

        try {
            if (lro_table_exists($table)) {
                $sql = "INSERT INTO `{$table}` (`action`,`admin_id`,`payload`,`created_at`)
                        VALUES (:action,:admin_id,:payload,NOW())";
                lro_pdo()->prepare($sql)->execute([
                    ':action'   => $action,
                    ':admin_id' => $adminId,
                    ':payload'  => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
                return;
            }
        } catch (\Throwable $e) {
            // fall through to file log
        }

        // Fallback file log (silent, no screen output)
        $line = '[' . date('Y-m-d H:i:s') . '] ' .
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) .
            PHP_EOL;
        @file_put_contents(LRO_ADMIN_DIR . '/admin.log', $line, FILE_APPEND);
    }
}

/**
 * --------------------------------------------------------------------------
 * 3) Auth gates (session-based) with optional capability checks
 * --------------------------------------------------------------------------
 */

/**
 * Require admin session. If $masterOnly = true, require master.
 */
if (!function_exists('lro_require_admin')) {
    function lro_require_admin(bool $masterOnly = false): void
    {
        $adminId = $_SESSION['admin_id'] ?? null;
        if (!$adminId) {
            http_response_code(403);
            exit('Forbidden');
        }
        if ($masterOnly) {
            $isMaster = !empty($_SESSION['is_master']) || !empty($_SESSION['lro_is_master']);
            if (!$isMaster) {
                http_response_code(403);
                exit('Forbidden');
            }
        }
    }
}

/** Require a specific capability flag (e.g., 'can_view_uploads') */
if (!function_exists('require_cap')) {
    function require_cap(string $cap): void
    {
        $adminId = $_SESSION['admin_id'] ?? null;
        if (!$adminId || empty($_SESSION[$cap])) {
            http_response_code(403);
            exit('Forbidden');
        }
    }
}

/**
 * Legacy shims:
 * Some existing admin pages may call require_admin_login()/require_master_login().
 */
if (!function_exists('require_admin_login')) {
    function require_admin_login(bool $masterOnly = false): void
    {
        lro_require_admin($masterOnly);
    }
}
if (!function_exists('require_master_login')) {
    function require_master_login(): void
    {
        lro_require_admin(true);
    }
}
