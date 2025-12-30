<?php
/**************************************************
 * modules/lrofileupload/admin/auth.php
 * Idempotent auth helpers (safe to include multiple times)
 **************************************************/
declare(strict_types=1);

// Make sure a session exists, but don't fatal if headers already sent.
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

if (!function_exists('lro_session_started')) {
    function lro_session_started(): bool {
        return session_status() === PHP_SESSION_ACTIVE;
    }
}

if (!function_exists('require_admin_login')) {
    /**
     * Require that an admin is logged in. If $master_only = true, require master.
     */
    function require_admin_login(bool $master_only = false): void {
        if (!lro_session_started()) { @session_start(); }

        $isLogged  = !empty($_SESSION['admin_id']);
        $isMaster  = !empty($_SESSION['is_master']) || !empty($_SESSION['lro_is_master']);

        if (!$isLogged) {
            http_response_code(403);
            exit('Forbidden (admins only)');
        }
        if ($master_only && !$isMaster) {
            http_response_code(403);
            exit('Forbidden (master only)');
        }
    }
}

if (!function_exists('require_master_login')) {
    function require_master_login(): void { require_admin_login(true); }
}

if (!function_exists('lro_has_cap')) {
    /**
     * Capability check. Masters always pass.
     * Accepts session flags like 'can_view_uploads' or 'lro_can_view_uploads',
     * or $_SESSION['caps'][<cap>] = true.
     */
    function lro_has_cap(string $cap): bool {
        $isMaster = !empty($_SESSION['is_master']) || !empty($_SESSION['lro_is_master']);
        if ($isMaster) return true;

        if (!empty($_SESSION[$cap]) || !empty($_SESSION['lro_' . $cap])) return true;
        if (!empty($_SESSION['caps']) && !empty($_SESSION['caps'][$cap])) return true;

        return false;
    }
}

if (!function_exists('require_cap')) {
    function require_cap(string $cap): void {
        if (!lro_has_cap($cap)) {
            http_response_code(403);
            exit('Forbidden (missing ' . $cap . ')');
        }
    }
}

if (!function_exists('lro_require_admin')) {
    /** Compatibility wrapper used in some files */
    function lro_require_admin(bool $master_only = false): void {
        require_admin_login($master_only);
    }
}
