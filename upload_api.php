<?php
/**************************************************
 * Path: /modules/lrofileupload/upload_api.php
 * JSON endpoints: list_groups, list_uploads, upload_file
 **************************************************/
declare(strict_types=1);

// You can relax these in production:
ini_set('display_errors','1'); ini_set('display_startup_errors','1'); error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

// ---- PrestaShop bootstrap ----
$psRoot = realpath(__DIR__ . '/../../'); // go from /modules/lrofileupload -> PS root
if (!$psRoot || !is_dir($psRoot)) {
  http_response_code(500); echo json_encode(['success'=>false,'message'=>'Bootstrap failed']); exit;
}
require_once $psRoot . '/config/config.inc.php';
require_once $psRoot . '/init.php';

// ---- Session & CSRF ----
if (session_status() === PHP_SESSION_NONE) session_start();
$csrf = (string)($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
  http_response_code(400);
  echo json_encode(['success'=>false,'message'=>'Invalid CSRF token']); exit;
}

// ---- Customer (require login) ----
$ctx = Context::getContext();
$customer = $ctx->customer ?? null;
$customerId = (int)($customer ? $customer->id : 0);
if ($customerId <= 0) { echo json_encode(['success'=>false,'message'=>'Please sign in first.']); exit; }

// ---- DB & helpers ----
$prefix = _DB_PREFIX_;
$db     = Db::getInstance();

function ok(array $a=[]){ echo json_encode(['success'=>true]+$a); exit; }
function err(string $m, array $a=[]){ echo json_encode(['success'=>false,'message'=>$m]+$a); exit; }

function hasColumn(string $table, string $column): bool {
  $q = "SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA='" . pSQL(_DB_NAME_) . "'
          AND TABLE_NAME='" . pSQL($table) . "'
          AND COLUMN_NAME='" . pSQL($column) . "'";
  return (bool)Db::getInstance()->getValue($q);
}

function ensureDir(string $path): bool { return is_dir($path) || @mkdir($path, 0775, true); }
function sanitizeName(string $name): string {
  $name = preg_replace('/[^\w\-.]+/u','_', $name);
  return trim((string)$name,'._');
}
function allowedExtForGroupRow(array $g): array {
  // Accept either 'allowed_ext' or 'file_type' as CSV list
  $s = strtolower(trim((string)($g['allowed_ext'] ?? $g['file_type'] ?? '')));
  if ($s==='') return ['pdf','jpg','jpeg','png','gif','webp'];
  $parts = array_filter(array_map('trim', explode(',', $s)));
  return $parts ?: ['pdf','jpg','jpeg','png','gif','webp'];
}

// ---- Tables ----
$tblUploads = $prefix.'lrofileupload_uploads';
$tblGroups  = $prefix.'lrofileupload_product_groups';

// ---- Storage root (NO hard-coded absolute paths) ----
// Prefer a configurable dir; fallback to safe defaults
$storageRoot = (string)Configuration::get('LRO_STORAGE_DIR');
if (!$storageRoot) {
  $candidates = [
    _PS_ROOT_DIR_.'/uploads_lrofileupload',              // outside module (preferred)
    _PS_MODULE_DIR_.'lrofileupload/uploads',             // inside module
    _PS_MODULE_DIR_.'lrofileupload/admin/uploads',       // admin uploads
  ];
  foreach ($candidates as $c) {
    if (!is_dir($c)) @mkdir($c, 0755, true);
    if (is_dir($c) && is_writable($c)) { $storageRoot = $c; break; }
  }
}
if (!$storageRoot) { err('Storage not writable'); }
$storageRoot = rtrim($storageRoot, '/');

// ---- Action dispatch ----
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

if ($action === 'list_groups') {
  // Build SELECT safely depending on available columns
  $cols = ['id_group', 'group_name'];
  $hasRequired   = hasColumn($tblGroups, 'required');
  $hasAllowedExt = hasColumn($tblGroups, 'allowed_ext');
  $hasFileType   = hasColumn($tblGroups, 'file_type');

  $select = 'id_group, group_name';
  $select .= $hasRequired ? ', IFNULL(required,0) AS required' : ', 0 AS required';

  if ($hasAllowedExt && $hasFileType) {
    $select .= ', IFNULL(allowed_ext, IFNULL(file_type,"")) AS allowed_ext';
  } elseif ($hasAllowedExt) {
    $select .= ', allowed_ext AS allowed_ext';
  } elseif ($hasFileType) {
    $select .= ', file_type AS allowed_ext';
  } else {
    $select .= ', "" AS allowed_ext';
  }

  $rows = $db->executeS("SELECT $select FROM {$tblGroups} ORDER BY group_name") ?: [];
  ok(['rows'=>$rows]);
}

if ($action === 'list_uploads') {
  // Build SELECT with column compatibility:
  $hasIdUpload   = hasColumn($tblUploads, 'id_upload');
  $hasIdCustomer = hasColumn($tblUploads, 'id_customer');
  $hasCustomerId = hasColumn($tblUploads, 'customer_id');
  $hasIdGroup    = hasColumn($tblUploads, 'id_group');
  $hasGroupId    = hasColumn($tblUploads, 'group_id');
  $hasFileName   = hasColumn($tblUploads, 'file_name');
  $hasOrigName   = hasColumn($tblUploads, 'original_name');
  $hasStatus     = hasColumn($tblUploads, 'status');
  $hasReject     = hasColumn($tblUploads, 'rejection_reason');
  $hasUploadedAt = hasColumn($tblUploads, 'uploaded_at');
  $hasCreatedAt  = hasColumn($tblUploads, 'created_at');
  $hasDateAdd    = hasColumn($tblUploads, 'date_add');

  $select = [];
  $select[] = $hasIdUpload ? 'u.id_upload' : 'NULL AS id_upload';
  // standardize to id_customer/id_group in output
  if ($hasIdCustomer)      $select[] = 'u.id_customer';
  elseif ($hasCustomerId)  $select[] = 'u.customer_id AS id_customer';
  else                     $select[] = (string)(int)$customerId.' AS id_customer';

  if ($hasIdGroup)         $select[] = 'u.id_group';
  elseif ($hasGroupId)     $select[] = 'u.group_id AS id_group';
  else                     $select[] = 'NULL AS id_group';

  $select[] = $hasFileName ? 'u.file_name' : 'NULL AS file_name';
  $select[] = $hasOrigName ? 'u.original_name' : 'NULL AS original_name';
  $select[] = $hasStatus   ? 'u.status' : '"pending" AS status';

  if ($hasReject)          $select[] = 'u.rejection_reason';
  else                     $select[] = '"" AS rejection_reason';

  if     ($hasUploadedAt)  $select[] = 'u.uploaded_at';
  elseif ($hasCreatedAt)   $select[] = 'u.created_at AS uploaded_at';
  elseif ($hasDateAdd)     $select[] = 'u.date_add AS uploaded_at';
  else                     $select[] = 'NOW() AS uploaded_at';

  $select[] = 'g.group_name';

  // WHERE for this customer (work with either id_customer or customer_id)
  $where = $hasIdCustomer
    ? 'u.id_customer='.(int)$customerId
    : ($hasCustomerId ? 'u.customer_id='.(int)$customerId : '1');

  $sql = 'SELECT '.implode(',', $select).'
          FROM '.$tblUploads.' u
          LEFT JOIN '.$tblGroups.' g ON g.id_group = '.($hasIdGroup ? 'u.id_group' : ($hasGroupId ? 'u.group_id' : 'NULL')).'
          WHERE '.$where.'
          ORDER BY uploaded_at DESC, id_upload DESC';

  $rows = $db->executeS($sql) ?: [];
  ok(['rows'=>$rows]);
}

if ($action === 'upload_file') {
  // Inputs
  $gid = (int)($_POST['group_id'] ?? 0);
  if ($gid <= 0) err('Missing group_id');
  if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) err('Missing file');

  // Group row to derive allowed extensions
  $hasAllowedExt = hasColumn($tblGroups, 'allowed_ext');
  $hasFileType   = hasColumn($tblGroups, 'file_type');
  $select = 'id_group, group_name';
  if ($hasAllowedExt && $hasFileType) {
    $select .= ', IFNULL(allowed_ext, IFNULL(file_type,"")) AS allowed_ext';
  } elseif ($hasAllowedExt) {
    $select .= ', allowed_ext AS allowed_ext';
  } elseif ($hasFileType) {
    $select .= ', file_type AS allowed_ext';
  } else {
    $select .= ', "" AS allowed_ext';
  }

  $group = $db->getRow("SELECT $select FROM {$tblGroups} WHERE id_group=".(int)$gid);
  if (!$group) err('Unknown group');

  $allowed = allowedExtForGroupRow($group);

  // Validate file
  $orig = sanitizeName((string)($_FILES['file']['name'] ?? 'upload.bin'));
  $ext  = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
  if ($ext==='' || !in_array($ext, $allowed, true)) {
    err('This file type is not allowed for the selected requirement (allowed: '.implode(', ',$allowed).')');
  }
  $maxSize = 25 * 1024 * 1024; // 25 MB default
  if ((int)$_FILES['file']['size'] > $maxSize) {
    err('File too large (max 25MB)');
  }

  // Build directory: /root/customer_123/group_5/
  $destDir = $storageRoot.'/customer_'.$customerId.'/group_'.$gid.'/';
  if (!ensureDir($destDir)) err('Storage error: cannot create directory');

  // Avoid collisions
  $base = pathinfo($orig, PATHINFO_FILENAME);
  $dest = $destDir . $orig;
  $i = 1;
  while (file_exists($dest)) {
    $dest = $destDir . sanitizeName($base).'('.$i.').'.$ext;
    $i++;
  }

  if (!@move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
    err('Failed to store the uploaded file');
  }

  // ----- Upsert upload row (supports both legacy & canonical schemas) -----
  $hasIdCustomer = hasColumn($tblUploads, 'id_customer');
  $hasCustomerId = hasColumn($tblUploads, 'customer_id');
  $hasIdGroup    = hasColumn($tblUploads, 'id_group');
  $hasGroupId    = hasColumn($tblUploads, 'group_id');
  $hasIdReq      = hasColumn($tblUploads, 'id_requirement'); // if present, we mirror group into requirement
  $hasOrigName   = hasColumn($tblUploads, 'original_name');
  $hasStatus     = hasColumn($tblUploads, 'status');
  $hasReject     = hasColumn($tblUploads, 'rejection_reason');
  $hasUploadedAt = hasColumn($tblUploads, 'uploaded_at');
  $hasDateAdd    = hasColumn($tblUploads, 'date_add');

  // Prepare columns/values
  $cols = [];
  if ($hasIdCustomer) $cols['id_customer'] = (int)$customerId;
  elseif ($hasCustomerId) $cols['customer_id'] = (int)$customerId;

  if ($hasIdGroup) $cols['id_group'] = (int)$gid;
  elseif ($hasGroupId) $cols['group_id'] = (int)$gid;

  if ($hasIdReq) $cols['id_requirement'] = (int)$gid; // map group to requirement if table has that column

  $cols['file_name'] = pSQL(basename($dest), true);
  if ($hasOrigName)  $cols['original_name'] = pSQL($orig, true);
  if ($hasStatus)    $cols['status'] = pSQL('pending');
  if ($hasReject)    $cols['rejection_reason'] = '';

  if ($hasUploadedAt) $cols['uploaded_at'] = date('Y-m-d H:i:s');
  elseif ($hasDateAdd) $cols['date_add'] = date('Y-m-d H:i:s');

  // Try UPDATE first if a row exists for this (customer + requirement/group)
  $whereParts = [];
  if     ($hasIdCustomer) $whereParts[] = 'id_customer='.(int)$customerId;
  elseif ($hasCustomerId) $whereParts[] = 'customer_id='.(int)$customerId;

  if     ($hasIdReq) $whereParts[] = 'id_requirement='.(int)$gid;
  elseif ($hasIdGroup) $whereParts[] = 'id_group='.(int)$gid;
  elseif ($hasGroupId) $whereParts[] = 'group_id='.(int)$gid;

  $where = implode(' AND ', $whereParts);
  $exists = false;
  if ($where) {
    $exists = (bool)$db->getValue('SELECT 1 FROM '.$tblUploads.' WHERE '.$where.' LIMIT 1');
  }

  if ($exists) {
    $okUpd = $db->update('lrofileupload_uploads', $cols, $where);
    if (!$okUpd) {
      err('DB update failed');
    }
  } else {
    $okIns = $db->insert('lrofileupload_uploads', $cols);
    if (!$okIns) {
      err('DB insert failed');
    }
  }

  ok(['message'=>'Uploaded', 'file'=>basename($dest)]);
}

err('Unknown action');
