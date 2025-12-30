<?php
/**************************************************
 * File: modules/lrofileupload/admin/approve.php
 * Purpose: Approve a file (POST)
 **************************************************/

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/auth.php';
require_admin_login();  // FIX: call the correct function

/* PrestaShop bootstrap */
if (!defined('_PS_VERSION_')) {
    $psRoot = realpath(__DIR__ . '/../../../');
    if ($psRoot === false || !file_exists($psRoot . '/config/config.inc.php')) {
        die('Cannot locate PrestaShop root');
    }
    require_once $psRoot . '/config/config.inc.php';
    require_once $psRoot . '/init.php';
}

/* CSRF check */
$serverCsrf = $_SESSION['csrf_token'] ?? '';
$postedCsrf = $_POST['csrf'] ?? $_POST['csrf_token'] ?? '';
if (!$serverCsrf || !$postedCsrf || !hash_equals($serverCsrf, $postedCsrf)) {
    header('Location: view_uploads.php?err=' . rawurlencode('Invalid CSRF token'));
    exit;
}

/* Validate input */
$fileIdRaw = $_POST['file_id'] ?? '';
if (!ctype_digit((string)$fileIdRaw)) {
    header('Location: view_uploads.php?err=' . rawurlencode('Missing or invalid file_id'));
    exit;
}
$fileId = (int)$fileIdRaw;

/* Get admin ID from session */
$adminId = (int)( $_SESSION['lro_admin_id'] ?? $_SESSION['admin_id'] ?? 0 );
if ($adminId <= 0) {
    header('Location: view_uploads.php?err=' . rawurlencode('Not authenticated'));
    exit;
}

$db     = Db::getInstance();
$prefix = _DB_PREFIX_;
$table  = $prefix . 'lrofileupload_uploads';
$pk     = 'id_upload';

/* Ensure file exists */
$checkSql = "SELECT `$pk` FROM `$table` WHERE `$pk` = $fileId";
if (!$db->getRow($checkSql)) {
    header('Location: view_uploads.php?err=' . rawurlencode("File not found (id=$fileId)"));
    exit;
}

/* Perform approval */
$sql = "
    UPDATE `$table`
    SET 
        `status` = 'approved',
        `rejection_reason` = NULL,
        `rejected_by` = NULL
    WHERE `$pk` = $fileId
    LIMIT 1
";

try {
    if ($db->execute($sql)) {
        header('Location: view_uploads.php?ok=' . rawurlencode("Approved file #$fileId"));
    } else {
        $err = method_exists($db, 'getMsgError') ? $db->getMsgError() : 'Update failed';
        header('Location: view_uploads.php?err=' . rawurlencode($err));
    }
} catch (Throwable $e) {
    header('Location: view_uploads.php?err=' . rawurlencode($e->getMessage()));
}
exit;
