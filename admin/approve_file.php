<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors','1'); ini_set('html_errors','0');
set_error_handler(function($n,$s,$f,$l){ if($n===E_DEPRECATED||$n===E_USER_DEPRECATED) return true; throw new ErrorException($s,0,$n,$f,$l); });

$HERE=__DIR__; $ROOT=realpath($HERE.'/../../..');
$bootA=$HERE.'/_bootstrap.php'; $bootB=$ROOT.'/config/config.inc.php';
if (is_file($bootA)) require_once $bootA; elseif (is_file($bootB)) require_once $bootB; else { http_response_code(500); die('bootstrap failed'); }
if (session_status()===PHP_SESSION_NONE) session_start();

if (function_exists('lro_require_admin')) lro_require_admin(false);
elseif (function_exists('require_admin_login')) require_admin_login(false);
elseif (empty($_SESSION['admin_id'])) { http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Forbidden'])); }

header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');

try {
  if ($_SERVER['REQUEST_METHOD']!=='POST') throw new Exception('Invalid method');
  if (!empty($_SESSION['csrf_token'])) {
    $t=$_POST['csrf_token']??''; if(!hash_equals($_SESSION['csrf_token'],$t)) throw new Exception('Bad CSRF token');
  }

  $fileId=(int)($_POST['file_id']??0);
  if($fileId<=0) throw new Exception('Missing file_id');
  $adminId=(int)($_SESSION['admin_id']??0);

  $tblNoPrefix = 'lrofileupload_uploads';             // <-- unprefixed for Db::update()
  $tblPref     = _DB_PREFIX_.$tblNoPrefix;            //     prefixed for raw SQL

  $data=[
    'status'=>'approved',
    'approved_by'=>$adminId,
    'approved_at'=>date('Y-m-d H:i:s'),
    'rejection_reason'=>null,'rejection_notes'=>null,'rejected_by'=>null,'rejected_at'=>null
  ];
  $null=[]; foreach($data as $k=>$v){ if($v===null){ $null[]="`$k`=NULL"; unset($data[$k]); } }

  $db=Db::getInstance();
  // IMPORTANT: pass UNPREFIXED table to Db::update()
  $ok=$db->update($tblNoPrefix, $data, 'id_upload='.(int)$fileId, 1, true);
  if($null){
    $db->execute('UPDATE `'.pSQL($tblPref).'` SET '.implode(', ',$null).' WHERE id_upload='.(int)$fileId.' LIMIT 1');
  }
  if(!$ok && !$null) throw new Exception('Update failed');

  echo json_encode(['ok'=>true,'file_id'=>$fileId,'status'=>'approved']);
} catch(Throwable $e){
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
