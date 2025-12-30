<?php
/**************************************************
 * Ajax: approve / reject / pending an upload
 * Path: modules/lrofileupload/admin/ajax_approve.php
 **************************************************/
declare(strict_types=1);

ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=UTF-8');

/* -------- PrestaShop bootstrap -------- */
(function () {
    $dir = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        if (file_exists($dir.'/config/config.inc.php') && file_exists($dir.'/init.php')) {
            require_once $dir.'/config/config.inc.php';
            require_once $dir.'/init.php';
            return;
        }
        $dir = dirname($dir);
    }
    $root = dirname(__DIR__, 3);
    require_once $root.'/config/config.inc.php';
    require_once $root.'/init.php';
})();

/* -------- Session & auth -------- */
require_once __DIR__.'/session_bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/auth.php';
require_admin_login();

/* -------- helpers -------- */
function jerr(string $msg, int $code = 400, array $extra = []){
    http_response_code($code);
    echo json_encode(['ok'=>false,'error'=>$msg] + $extra);
    exit;
}
function tblExists(string $full): bool {
    return (bool)Db::getInstance()->getValue(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA='".pSQL(_DB_NAME_)."' AND TABLE_NAME='".pSQL($full)."'"
    );
}
function colExists(string $full, string $col): bool {
    return (bool)Db::getInstance()->getValue(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA='".pSQL(_DB_NAME_)."' AND TABLE_NAME='".pSQL($full)."'
           AND COLUMN_NAME='".pSQL($col)."'"
    );
}
function pickCol(string $full, array $cands, ?string $fallback=null): ?string {
    foreach ($cands as $c) if (colExists($full,$c)) return $c;
    return $fallback;
}
function firstRow(string $sql): ?array {
    // Use executeS (NO implicit LIMIT). Take first row in PHP.
    $rows = Db::getInstance()->executeS($sql);
    if (!$rows || !is_array($rows) || !isset($rows[0])) return null;
    return $rows[0];
}
function firstValue(string $sql) {
    $r = firstRow($sql);
    if (!$r) return null;
    $vals = array_values($r);
    return $vals ? $vals[0] : null;
}

/* -------- resolve table & columns -------- */
$db     = Db::getInstance();
$prefix = _DB_PREFIX_;
$tbl    = $prefix.'lrofileupload_uploads';
if (!tblExists($tbl)) jerr("Uploads table not found: $tbl", 500);

$idCol      = pickCol($tbl, ['id_upload','upload_id','id','id_file'], 'id_upload');
$custCol    = pickCol($tbl, ['id_customer','customer_id','customer'], 'id_customer');
$reqCol     = pickCol($tbl, ['id_requirement','requirement_id','req_id','rid'], 'id_requirement');
$grpCol     = pickCol($tbl, ['id_group','group_id','gid','groupid']);
$fileCol    = pickCol($tbl, ['file_name','filename','name'], 'file_name');
$origCol    = pickCol($tbl, ['original_name','original','orig_name']);
$statusCol  = pickCol($tbl, ['status','state'], 'status');
$reasonCol  = pickCol($tbl, ['reason','rejection_reason','reject_reason']);
$whenCol    = pickCol($tbl, ['uploaded_at','date_uploaded','date_add','created_at','created','ts'], 'uploaded_at');
$approvedBy = pickCol($tbl, ['approved_by','reviewed_by','moderated_by']);
$approvedAt = pickCol($tbl, ['approved_at','reviewed_at','moderated_at']);
$rejectedAt = pickCol($tbl, ['rejected_at']);

if (!$idCol || !$statusCol) jerr('Critical columns missing on uploads table.', 500);

/* -------- input -------- */
$action = Tools::strtolower(trim((string)(Tools::getValue('action', ''))));
if ($action === '') {
    // infer: if a reason was supplied -> reject, else approve
    $action = (Tools::getValue('reason') !== false || Tools::getValue('reason_id') !== false || Tools::getValue('reason_text') !== false)
        ? 'reject' : 'approve';
}
$reason = trim((string)Tools::getValue('reason'));
$notes  = trim((string)Tools::getValue('notes'));

// Accept many id parameter names
$id = 0;
foreach (['file_id','id_upload','upload_id','id_file','id'] as $k) {
    $v = (int)Tools::getValue($k, 0);
    if ($v > 0) { $id = $v; break; }
}

// Fallback: by filename
if ($id <= 0) {
    $fname = (string)Tools::getValue('filename', Tools::getValue('file',''));
    if ($fname !== '') {
        $orderCol = $whenCol ?: $idCol;
        $sql = 'SELECT `'.bqSQL($idCol).'` AS id
                  FROM `'.bqSQL($tbl).'`
                 WHERE `'.bqSQL($fileCol)."`='".pSQL($fname)."'
                 ORDER BY `".bqSQL($orderCol)."` DESC";
        try {
            $row = firstRow($sql);
            if ($row && isset($row['id'])) $id = (int)$row['id'];
        } catch (Exception $e) {
            jerr('SQL error locating upload by filename', 500, ['sql_debug'=>$sql,'exception'=>$e->getMessage()]);
        }
    }
}
if ($id <= 0) jerr('Missing id_upload (also tried file/filename)');

/* -------- load row (no implicit LIMIT by using executeS) -------- */
$sqlLoad = 'SELECT * FROM `'.bqSQL($tbl).'` WHERE `'.bqSQL($idCol).'`='.(int)$id;
try {
    $row = firstRow($sqlLoad);
} catch (Exception $e) {
    jerr('SQL error loading upload', 500, ['sql_debug'=>$sqlLoad,'exception'=>$e->getMessage()]);
}
if (!$row) jerr('Upload not found', 404);

/* -------- resolve rejection reason (if required) -------- */
if ($action === 'reject' && $reason === '') {
    $rid = (int)Tools::getValue('reason_id', 0);
    $rtx = trim((string)Tools::getValue('reason_text', ''));
    if ($rtx !== '') {
        $reason = $rtx;
    } elseif ($rid > 0) {
        $tA = $prefix.'lrofileupload_reasons';
        $tB = $prefix.'lrofileupload_rejection_reasons';
        $sqlR = '';
        if (tblExists($tA)) {
            $col = colExists($tA,'reason_text') ? 'reason_text' : (colExists($tA,'reason') ? 'reason' : null);
            if ($col) $sqlR = 'SELECT `'.bqSQL($col).'` AS v FROM `'.bqSQL($tA).'` WHERE `id_reason`='.(int)$rid;
        } elseif (tblExists($tB)) {
            $col = colExists($tB,'reason_text') ? 'reason_text' : (colExists($tB,'reason') ? 'reason' : null);
            if ($col) $sqlR = 'SELECT `'.bqSQL($col).'` AS v FROM `'.bqSQL($tB).'` WHERE `id_reason`='.(int)$rid;
        }
        if ($sqlR !== '') {
            try { $val = firstValue($sqlR); if ($val) $reason = (string)$val; }
            catch (Exception $e) { jerr('SQL error loading reason text', 500, ['sql_debug'=>$sqlR,'exception'=>$e->getMessage()]); }
        }
    }
}

/* -------- build update -------- */
$ctx        = Context::getContext();
$employeeId = (int)($ctx->employee->id ?? 0);
$now        = date('Y-m-d H:i:s');

$upd = [];
switch ($action) {
    case 'approve':
        $upd[$statusCol] = 'approved';
        if ($reasonCol)    $upd[$reasonCol] = null;
        if ($approvedAt)   $upd[$approvedAt] = $now;
        if ($approvedBy)   $upd[$approvedBy] = $employeeId;
        break;

    case 'reject':
        if ($reason === '') jerr('Provide a rejection reason');
        $upd[$statusCol] = 'rejected';
        if ($reasonCol)  $upd[$reasonCol] = pSQL($reason.(strlen($notes)?(" | ".$notes):""));
        if ($rejectedAt) $upd[$rejectedAt] = $now;
        if ($approvedBy) $upd[$approvedBy] = $employeeId;
        break;

    case 'pending':
    case 'reset':
        $upd[$statusCol] = 'pending';
        if ($reasonCol)  $upd[$reasonCol] = null;
        break;

    default:
        jerr('Unknown action (use approve|reject|pending)');
}

/* -------- perform update -------- */
$where = '`'.bqSQL($idCol).'`='.(int)$id;
try {
    $ok = $db->update('lrofileupload_uploads', $upd, $where, 0, true);
} catch (Exception $e) {
    jerr('DB update failed', 500, ['exception'=>$e->getMessage()]);
}
if (!$ok) jerr('DB update failed', 500);

/* -------- success -------- */
echo json_encode([
    'ok'      => true,
    'id'      => $id,
    'action'  => $action,
    'status'  => $upd[$statusCol] ?? null,
]);
