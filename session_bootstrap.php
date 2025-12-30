<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['__lro_started'])) $_SESSION['__lro_started'] = time();

/** Only declare once to avoid “Cannot redeclare …” */
if (!function_exists('require_admin_login')) {
    function require_admin_login(): void {
        if (empty($_SESSION['admin_id'])) {
            header('Location: admin_login.php');
            exit;
        }
    }
}
