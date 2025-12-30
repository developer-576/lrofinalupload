<?php
// modules/lrofileupload/admin/ajax_reject.php
declare(strict_types=1);

require_once __DIR__.'/_bootstrap.php';

/* -------- Auth -------- */
if (function_exists('lro_require_admin')) {
    lro_require_admin(false);
} elseif (function_exists('require_admin_login')) {
    require_admin_login(false);
} else {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['admin_id'])) { http_response_code(403); exit('Forbidden'); }
}
if (function_exists('require_cap')) { require_cap('can_manage_rejections'); }

header('Content-Type: application/json; charset=utf-8');

/* -------- CSRF (accept csrf_token OR csrf) -------- */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$tk = (string)($_POST['csrf_token'] ?? $_POST['csrf'] ?? '');
if (!$tk || !hash_equals($_SESSION['csrf_token'], $tk)) {
    echo json_encode(['success'=>false,'error'=>'Bad CSRF']); exit;
}

/* -------- Input -------- */
$adminId    = (int)($_SESSION['admin_id'] ?? 0);
$fileIdIn   = (int)($_POST['file_id'] ?? $_POST['id_upload'] ?? $_POST['upload_id'] ?? $_POST['id'] ?? 0);
$reasonId   = (int)($_POST['reason_id'] ?? 0);
$reasonText = trim((string)($_POST['reason_text'] ?? $_POST['reason'] ?? ''));
$notes      = trim((string)($_POST['notes'] ?? ''));

if ($adminId <= 0 || $fileIdIn <= 0) {
    echo json_encode(['success'=>false,'error'=>'Invalid input']); exit;
}

/* -------- DB -------- */
$prefix = defined('_DB_PREFIX_') ? _DB_PREFIX_ : 'psfc_';
$db     = class_exists('Db') ? Db::getInstance() : null;
if (!$db) { echo json_encode(['success'=>false,'error'=>'DB unavailable']); exit; }

/* Helpers for detection */
$hasTable = function (string $table) use ($db): bool {
    try { return (bool)$db->getValue("SHOW TABLES LIKE '".pSQL($table)."'"); } catch (Throwable $e) { return false; }
};
$hasCol = function (string $table, string $col) use ($db): bool {
    try { return (bool)$db->getValue("SHOW COLUMNS FROM `{$table}` LIKE '".pSQL($col)."'"); } catch (Throwable $e) { return false; }
};

/* Detect canonical reasons table/column safely */
$tblReasons = $prefix.'lrofileupload_reasons';
if (!$hasTable($tblReasons)) {
    $tblReasons = $prefix.'lrofileupload_rejection_reasons';
}
$reasonExpr = 'reason_text';
if (!$hasCol($tblReasons, 'reason_text') && $hasCol($tblReasons, 'reason')) {
    $reasonExpr = 'reason';
} elseif ($hasCol($tblReasons, 'reason_text') && $hasCol($tblReasons, 'reason')) {
    // both exist — safe to COALESCE
    $reasonExpr = 'COALESCE(reason_text, reason)';
}

/* If only id given, pull text */
if ($reasonId > 0 && $reasonText === '') {
    $reasonText = (string)$db->getValue(
        "SELECT {$reasonExpr} FROM `{$tblReasons}` WHERE id_reason=".(int)$reasonId
    );
}

/* Locate upload row (support file_id or id_upload PK) */
$tblUploads = $prefix.'lrofileupload_uploads';
$pkCol = 'file_id';
$row = $db->getRow("SELECT *, file_id FROM `{$tblUploads}` WHERE file_id={$fileIdIn} LIMIT 1");
if (!$row) {
    $pkCol = 'id_upload';
    $row = $db->getRow("SELECT *, id_upload AS file_id FROM `{$tblUploads}` WHERE id_upload={$fileIdIn} LIMIT 1");
}
if (!$row) { echo json_encode(['success'=>false,'error'=>'File not found']); exit; }

$fileId      = (int)$row['file_id']; // normalized
$idCustomer  = (int)($row['id_customer'] ?? 0);
$idGroup     = (int)($row['id_group'] ?? 0);
$idReq       = (int)($row['id_requirement'] ?? 0);
$origName    = (string)($row['original_name'] ?? $row['file_name'] ?? '');
$reqName     = (string)($row['requirement_name'] ?? '');

/* Fallback: fetch requirement_name by id_requirement if not present */
if ($reqName === '' && $idReq > 0) {
    $tblReq = $prefix.'lrofileupload_group_requirements';
    if ($hasTable($tblReq) && $hasCol($tblReq, 'requirement_name')) {
        $reqName = (string)$db->getValue("SELECT requirement_name FROM `{$tblReq}` WHERE id_requirement=".(int)$idReq);
    }
}

/* Persist rejection */
$set = [];
$set[] = "status='rejected'";
$set[] = "rejected_at=NOW()";
$set[] = "rejected_by_admin=".(int)$adminId;
$set[] = "rejection_reason_id=".($reasonId ?: "NULL");
$set[] = "rejection_reason_text=".($reasonText !== '' ? "'".pSQL($reasonText)."'" : "NULL");
// Optional notes column — uncomment if it exists in your table
// if ($notes !== '' && $hasCol($tblUploads, 'rejection_notes')) $set[] = "rejection_notes='".pSQL($notes)."'";

$ok = $db->execute("UPDATE `{$tblUploads}` SET ".implode(',', $set)." WHERE `{$pkCol}`={$fileIdIn}");
if (!$ok) { echo json_encode(['success'=>false,'error'=>'Update failed']); exit; }

/* -------- Action logging -------- */
$logTbl = $prefix.'lrofileupload_action_logs';
if ($hasTable($logTbl)) {
    $ip = @inet_pton($_SERVER['REMOTE_ADDR'] ?? '') ?: null;
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $meta = [
        'original_name' => $origName,
        'requirement'   => $reqName,
        'notes'         => $notes,
    ];
    $db->execute("INSERT INTO `{$logTbl}`
      (admin_id, action, file_id, customer_id, id_group, reason_id, reason_text, ip, ua, meta_json, created_at)
      VALUES
      (".(int)$adminId.", 'reject', ".(int)$fileId.", ".($idCustomer ?: "NULL").", ".($idGroup ?: "NULL").",
       ".($reasonId ?: "NULL").", ".($reasonText !== '' ? "'".pSQL($reasonText)."'" : "NULL").",
       ".($ip ? "UNHEX('".bin2hex($ip)."')" : "NULL").", '".pSQL($ua)."', '".pSQL(json_encode($meta))."', NOW())");
}

if (function_exists('admin_log')) {
    admin_log('file:reject', [
        'file_id'   => $fileId,
        'admin_id'  => $adminId,
        'reason_id' => $reasonId ?: null,
        'reason'    => $reasonText ?: null,
    ]);
}

/* -------- Optional email to customer (via helper if present) -------- */
try {
    $mailerHelper = __DIR__.'/mailer_helpers.php';
    if (is_file($mailerHelper)) {
        require_once $mailerHelper;

        // Pull customer info
        $first = $last = $toEmail = '';
        if ($idCustomer > 0) {
            $cRow    = $db->getRow("SELECT email, firstname, lastname FROM `{$prefix}customer` WHERE id_customer=".(int)$idCustomer);
            $toEmail = (string)($cRow['email'] ?? '');
            $first   = (string)($cRow['firstname'] ?? '');
            $last    = (string)($cRow['lastname'] ?? '');
        }

        // Group name (tolerant)
        $groupName = '';
        $grpTbl = $prefix.'lrofileupload_product_groups';
        if ($idGroup && $hasTable($grpTbl)) {
            if ($hasCol($grpTbl,'group_name')) {
                $groupName = (string)$db->getValue("SELECT group_name FROM `{$grpTbl}` WHERE id_group=".(int)$idGroup);
            } elseif ($hasCol($grpTbl,'name')) {
                $groupName = (string)$db->getValue("SELECT name FROM `{$grpTbl}` WHERE id_group=".(int)$idGroup);
            } elseif ($hasCol($grpTbl,'title')) {
                $groupName = (string)$db->getValue("SELECT title FROM `{$grpTbl}` WHERE id_group=".(int)$idGroup);
            }
        }

        // Tokens and send
        $tokens = lro_default_tokens([
            'firstname'         => $first,
            'lastname'          => $last,
            'group_name'        => $groupName,
            'requirement_name'  => $reqName,
            'rejection_reason'  => $reasonText,
        ]);

        if ($toEmail && filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            @lro_compose_and_send('reject', $tokens, $toEmail, trim("$first $last"));
        }
    }
} catch (Throwable $e) {
    // email failures are non-fatal; fall through
}

echo json_encode(['success'=>true,'file_id'=>$fileId]);
