<?php
/**************************************************
 * Reject via POST + redirect
 **************************************************/
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/auth.php';
require_admin_login(); // ✅ Fixed here

// PrestaShop
if (!defined('_PS_VERSION_')) {
    $psRoot = realpath(__DIR__ . '/../../../');
    if ($psRoot === false || !file_exists($psRoot . '/config/config.inc.php')) {
        die('Cannot locate PrestaShop root');
    }
    require_once $psRoot . '/config/config.inc.php';
    require_once $psRoot . '/init.php';
}

function back(string $msg, bool $ok = false): void {
    $loc = 'view_uploads.php?' . ($ok ? 'ok=' : 'err=') . rawurlencode($msg);
    header("Location: $loc");
    exit;
}

$serverCsrf = $_SESSION['csrf_token'] ?? '';
$postedCsrf = $_POST['csrf'] ?? $_POST['csrf_token'] ?? '';
if (!$serverCsrf || !$postedCsrf || !hash_equals($serverCsrf, $postedCsrf)) {
    back('Invalid CSRF token');
}

$adminId = (int)($_SESSION['admin_id'] ?? 0);
if ($adminId <= 0) back('Not authenticated');

$fileIdRaw = $_POST['file_id'] ?? '';
if ($fileIdRaw === '' || !ctype_digit($fileIdRaw)) back('Missing or invalid file_id');
$fileId = (int)$fileIdRaw;

$preset = trim((string)($_POST['reason_preset'] ?? ''));
$custom = trim((string)($_POST['reason_custom'] ?? ''));
$reason = $custom !== '' ? $custom : $preset;
if ($reason === '') back('Missing rejection reason');

$db     = Db::getInstance();
$prefix = _DB_PREFIX_;
$table  = $prefix . 'lrofileupload_uploads';
$pk     = 'id_upload';

$reasonSql = pSQL($reason, true);

$sql = "
UPDATE `$table`
SET `status` = 'rejected',
    `rejection_reason` = '$reasonSql',
    `rejected_by` = $adminId
WHERE `$pk` = $fileId
LIMIT 1";

try {
    if (!$db->execute($sql)) {
        $msg = method_exists($db, 'getMsgError') ? $db->getMsgError() : 'Update failed';
        back($msg);
    }
} catch (Throwable $e) {
    back($e->getMessage());
}

back("Rejected file #$fileId", true);
