<?php
declare(strict_types=1);

require_once __DIR__ . '/session_bootstrap.php';
require_once dirname(__FILE__, 4) . '/config/config.inc.php';
require_once dirname(__FILE__, 4) . '/init.php';
require_once __DIR__ . '/auth.php';
require_admin_login();
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    exit('POST only');
}

// ---- CSRF ----
$csrfToken = $_POST['csrf_token'] ?? '';
if (!function_exists('check_csrf')) {
    function check_csrf($token): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$token)) {
            http_response_code(400);
            exit('Invalid CSRF token');
        }
    }
}
check_csrf($csrfToken);

// ---- DB / tables ----
$prefix = _DB_PREFIX_;
$db     = Db::getInstance();
$tbl    = $prefix . 'lrofileupload_uploads';

// Helper: detect column existence (works on all hosts with information_schema access)
function lro_has_col(string $table, string $col): bool {
    return (bool)Db::getInstance()->getValue(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA='".pSQL(_DB_NAME_)."'
           AND TABLE_NAME='".pSQL($table)."'
           AND COLUMN_NAME='".pSQL($col)."'"
    );
}

// Determine column names across variants
$idCol          = lro_has_col($tbl, 'id_upload') ? 'id_upload' : (lro_has_col($tbl, 'id_file') ? 'id_file' : null);
$reasonIdCol    = lro_has_col($tbl, 'rejection_reason_id') ? 'rejection_reason_id' : (lro_has_col($tbl, 'reason_id') ? 'reason_id' : null);
$statusCol      = lro_has_col($tbl, 'status') ? 'status' : null;
$reviewedByCol  = lro_has_col($tbl, 'reviewed_by') ? 'reviewed_by' : null;
$reviewedAtCol  = lro_has_col($tbl, 'reviewed_at') ? 'reviewed_at' : (lro_has_col($tbl, 'date_reviewed') ? 'date_reviewed' : null);

if ($idCol === null) {
    http_response_code(500);
    exit('Uploads table id column not found (expected id_upload or id_file).');
}

// ---- Inputs ----
$fileId   = (int)Tools::getValue('file_id', 0);
$reasonId = (int)Tools::getValue('reason_id', 0);
if ($fileId <= 0 || $reasonId <= 0) {
    http_response_code(400);
    exit('Missing file or reason.');
}

// Who reviewed?
$reviewedBy = (int)($_SESSION['admin_id'] ?? (Context::getContext()->employee->id ?? 0));

// ---- Build update payload ----
$update = [];
if ($statusCol)     $update[$statusCol]     = pSQL('rejected');
if ($reasonIdCol)   $update[$reasonIdCol]   = (int)$reasonId;
if ($reviewedByCol) $update[$reviewedByCol] = $reviewedBy;
if ($reviewedAtCol) $update[$reviewedAtCol] = date('Y-m-d H:i:s');

if (!$update) {
    http_response_code(500);
    exit('No updatable columns found.');
}

// ---- Execute ----
$where  = $idCol . '=' . (int)$fileId;
$result = $db->update('lrofileupload_uploads', $update, $where);

// Optional logging
if (function_exists('debug_log')) {
    debug_log("rejections.php UPDATE {$tbl} SET ".json_encode($update)." WHERE {$where} | result=".var_export($result, true));
}

if ($result) {
    header('Location: master_dashboard.php?rejected=1');
    exit;
}

echo 'Error: Could not update rejection.';
