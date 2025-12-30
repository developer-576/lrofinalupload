<?php
/** modules/lrofileupload/admin/ajax_replace_upload.php */
declare(strict_types=1);

require_once __DIR__.'/_bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (function_exists('lro_require_admin')) lro_require_admin(false);
header('Content-Type: application/json; charset=utf-8');

function jdie(array $p, int $code=200){ http_response_code($code); echo json_encode($p, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit; }
if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) jdie(['success'=>false,'error'=>'CSRF'], 403);

$db     = Db::getInstance();
$prefix = _DB_PREFIX_;
$base   = rtrim(Tools::getShopDomainSsl(true), '/') . rtrim(__PS_BASE_URI__ ?? '/', '/');

$cid   = (int)($_POST['id_customer'] ?? 0);
$gid   = (int)($_POST['id_group'] ?? 0);
$rid   = (int)($_POST['id_requirement'] ?? 0);
$label = (string)($_POST['requirement'] ?? '');
if ($cid<=0) jdie(['success'=>false,'error'=>'Bad customer'], 400);
if (!isset($_FILES['upload'])) jdie(['success'=>false,'error'=>'No file'], 400);

function storage_roots(): array {
  $roots = [];
  if (defined('LRO_STORAGE_BASE')) { $rp=realpath((string)LRO_STORAGE_BASE); if($rp && is_dir($rp)) $roots[] = rtrim($rp,'/'); }
  if (class_exists('Configuration')) { $cfg=(string)Configuration::get('LRO_STORAGE_BASE'); if($cfg){ $rp=realpath($cfg); if($rp && is_dir($rp)) $roots[] = rtrim($rp,'/'); } }
  foreach (['/home/mfjprqzu/uploads_lrofileupload', _PS_ROOT_DIR_.'/../uploads_lrofileupload', _PS_MODULE_DIR_.'lrofileupload/uploads'] as $c){
    $rp=realpath($c); if($rp && is_dir($rp) && !in_array(rtrim($rp,'/'),$roots,true)) $roots[] = rtrim($rp,'/');
  }
  return $roots ?: [_PS_MODULE_DIR_.'lrofileupload/uploads'];
}
function ensure_dir(string $d): bool { return is_dir($d) ?: @mkdir($d,0750,true); }
function safe_filename(string $n): string { $n=preg_replace('/[^\p{L}\p{N}\.\-\_\s]+/u','_',$n)??'file'; $n=trim($n)?:'file'; if(mb_strlen($n)>180){$e=pathinfo($n,PATHINFO_EXTENSION);$b=mb_substr(pathinfo($n,PATHINFO_FILENAME),0,160);$n=$b.($e?'.'.$e:'');} return $n; }
function uniqify(string $n): string { $e=pathinfo($n,PATHINFO_EXTENSION); $b=pathinfo($n,PATHINFO_FILENAME); return $b.'_'.time().'_'.mt_rand(1000,9999).($e?'.'.$e:''); }
function allow_mime(string $ext,string $mime): bool { $okE=['pdf','jpg','jpeg','png','gif','webp']; $okM=['application/pdf','image/jpeg','image/png','image/gif','image/webp']; return in_array($ext,$okE,true)||in_array($mime,$okM,true); }

$f   = $_FILES['upload'];
$err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
if ($err !== UPLOAD_ERR_OK) jdie(['success'=>false,'error'=>'Upload error '.$err], 400);
$tmp = (string)$f['tmp_name'];
$orig= (string)$f['name'];
$size= (int)$f['size'];
if ($size<=0 || $size>50*1024*1024) jdie(['success'=>false,'error'=>'File size invalid'], 400);

$mime = '';
if (function_exists('finfo_open')) { $h=@finfo_open(FILEINFO_MIME_TYPE); if($h){ $m=@finfo_file($h,$tmp); @finfo_close($h); if($m) $mime=strtolower($m);} }
if (!$mime) $mime = strtolower((string)($f['type'] ?? 'application/octet-stream'));
$ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
if (!allow_mime($ext,$mime)) jdie(['success'=>false,'error'=>'Type not allowed'], 400);

// Pick first writable root
$roots = storage_roots();
$dest  = $roots[0];
$relDir= 'customer_'.$cid.'/group_'.$gid;
$absDir= $dest.'/'.$relDir;
if (!ensure_dir($absDir)) jdie(['success'=>false,'error'=>'Cannot create folder'], 500);

$safe = safe_filename($orig);
$new  = uniqify($safe);
$abs  = $absDir.'/'.$new;

if (!@move_uploaded_file($tmp, $abs)) jdie(['success'=>false,'error'=>'Move failed'], 500);
@chmod($abs, 0640);

// Upsert DB row; mark approved
$tbl = $prefix.'lrofileupload_uploads';
$cols = $db->executeS("SELECT COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".pSQL($tbl)."'") ?: [];
$have=[]; foreach($cols as $r){ $have[strtolower($r['c'])]=true; }

$uCust    = isset($have['id_customer'])?'id_customer':(isset($have['customer_id'])?'customer_id':'id_customer');
$uReq     = isset($have['id_requirement'])?'id_requirement':(isset($have['requirement_id'])?'requirement_id':(isset($have['req_id'])?'req_id':'id_requirement'));
$uGrp     = isset($have['id_group'])?'id_group':(isset($have['group_id'])?'group_id':null);
$uFile    = isset($have['file_name'])?'file_name':(isset($have['filename'])?'filename':(isset($have['name'])?'name':'file_name'));
$uOrig    = isset($have['original_name'])?'original_name':(isset($have['original'])?'original':(isset($have['orig_name'])?'orig_name':null));
$uStatus  = isset($have['status'])?'status':(isset($have['state'])?'state':'status');
$uWhen    = isset($have['uploaded_at'])?'uploaded_at':(isset($have['date_uploaded'])?'date_uploaded':(isset($have['date_add'])?'date_add':(isset($have['created_at'])?'created_at':(isset($have['created'])?'created':'uploaded_at'))));
$uReqName = isset($have['requirement_name'])?'requirement_name':(isset($have['req_name'])?'req_name':(isset($have['document_name'])?'document_name':(isset($have['doc_name'])?'doc_name':null)));

$now = date('Y-m-d H:i:s');
$sets = [];
$sets[$uCust] = (int)$cid;
if ($uReq)     $sets[$uReq] = (int)$rid;
if ($uGrp)     $sets[$uGrp] = (int)$gid;
$sets[$uFile]  = pSQL($new);
if ($uOrig)    $sets[$uOrig] = pSQL($orig);
$sets[$uStatus]= 'approved';
$sets[$uWhen]  = pSQL($now);
if ($uReqName) $sets[$uReqName] = pSQL($label);

$colsA = array_keys($sets);
$valsA = array_map(static fn($v)=> is_int($v)?(string)$v : "'".pSQL((string)$v)."'", array_values($sets));
$upd   = [];
foreach ($sets as $k=>$v){ if ($k===$uCust || $k===$uReq) continue; $upd[]='`'.bqSQL($k).'` = VALUES(`'.bqSQL($k).'`)'; }
$sql = "INSERT INTO `".bqSQL($tbl)."` (`".implode('`,`', array_map('bqSQL',$colsA))."`)"
     . " VALUES (".implode(',', $valsA).")"
     . " ON DUPLICATE KEY UPDATE ".implode(',', $upd);
$ok = $db->execute($sql);
if (!$ok) { @unlink($abs); $msg = method_exists($db,'getMsgError') ? $db->getMsgError() : 'DB write failed'; jdie(['success'=>false,'error'=>$msg], 500); }

$relPath  = $relDir.'/'.$new;
$serveUrl = $base.'/modules/lrofileupload/admin/serve_file_safe.php?file='.rawurlencode($relPath);

jdie([
  'success'=>true,
  'rel_path'=>$relPath,'file_name'=>$new,'uploaded_at'=>$now,'serve_url'=>$serveUrl,
  'id_customer'=>$cid,'id_group'=>$gid,'id_requirement'=>$rid
], 200);
