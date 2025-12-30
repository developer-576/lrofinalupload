<?php
// modules/lrofileupload/admin/logout.php
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';
require_once __DIR__.'/auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
admin_log('auth:logout', ['admin_id'=>$_SESSION['admin_id'] ?? null]);

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

lro_redirect('login.php');
