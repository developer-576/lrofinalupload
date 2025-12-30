<?php
// modules/lrofileupload/admin/reject_file.php
declare(strict_types=1);
ini_set('display_errors','1'); ini_set('display_startup_errors','1'); error_reporting(E_ALL);

require_once __DIR__.'/_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

// auth
if (function_exists('lro_require_admin')) { lro_require_admin(false); }
elseif (function_exists('require_admin_login')) { require_admin_login(false); }
else { session_start(); if (empty($_SESSION['admin_id'])) { http_response_code(403); echo json_encode(['success'=>false,'error'=>'forbidden']); exit; } }

$db  = Db::getInstance();
$P   = _DB_PREFIX_;
$tbl = $P.'lrofileupload_uploads';

// columns
$cols = $db->executeS("SELECT COLUMN_NAME c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".pSQL($tbl)."'") ?: [];
$have=[]; foreach($cols as $r) $have[strtolower((string)$r['c'])]=true;
$col_id = isset($have['id_upload'])?'id_upload':(isset($have['upload_id'])?'upload_id':'id');
$col_fn = isset($have['file_name'])?'file_name':(isset($have['filename'])?'filename':'name');
$col_st = isset($have['status'])?'status':'state';
$col_rej= isset($have['rejection_reason'])?'rejection_reason':(isset($have['reject_reason'])?'reject_reason':null);

$id = (int)($_POST['id_upload'] ?? 0);
$file = trim((string)($_POST['file'] ?? ''));
$reason = trim((string)($_POST['reason'] ?? ''));

if ($reason===''){ echo json_encode(['success'=>false,'error'=>'Missing reason']); exit; }

try{
  $set = "`$col_st`='rejected'".($col_rej? ", `$col_rej`='".pSQL($reason)."'":'');
  if ($id>0){
    $ok = $db->execute("UPDATE `$tbl` SET $set WHERE `$col_id`=".(int)$id." LIMIT 1");
  } elseif ($file!==''){
    $ok = $db->execute("UPDATE `$tbl` SET $set WHERE `$col_fn`='".pSQL($file)."' LIMIT 1");
  } else {
    echo json_encode(['success'=>false,'error'=>'Missing file']); exit;
  }
  if (!$ok) { echo json_encode(['success'=>false,'error'=>'db']); exit; }

  echo json_encode(['success'=>true]);
}catch(Throwable $e){
  http_response_code(500);
  echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
