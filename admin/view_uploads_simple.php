<?php
// modules/lrofileupload/admin/view_uploads_simple.php
declare(strict_types=1);
ini_set('display_errors','1'); ini_set('display_startup_errors','1'); error_reporting(E_ALL);

/* Bootstrap */
(function(){ $d=__DIR__; for($i=0;$i<8;$i++){ if(is_file($d.'/config/config.inc.php')&&is_file($d.'/init.php')){ require_once $d.'/config/config.inc.php'; require_once $d.'/init.php'; return;} $d=dirname($d);} $r=dirname(__DIR__,3); if(is_file($r.'/config/config.inc.php')&&is_file($r.'/init.php')){ require_once $r.'/config/config.inc.php'; require_once $r.'/init.php'; return;} header('Content-Type:text/plain',true,500); exit('Bootstrap failed'); })();

/* Auth */
require_once __DIR__.'/session_bootstrap.php';
if (function_exists('lro_require_admin')) lro_require_admin(false);
elseif (function_exists('require_admin_login')) require_admin_login(false);
else { if (session_status()!==PHP_SESSION_ACTIVE) session_start(); if (empty($_SESSION['admin_id'])) { http_response_code(403); exit('Admins only'); } }

$db = Db::getInstance(); $P=_DB_PREFIX_;
function h($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function tableExists(Db $db,string $t):bool{ return (int)$db->getValue("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".pSQL($t)."'")>0; }
function cols(Db $db,string $t):array{ if(!tableExists($db,$t))return[]; $r=$db->executeS("SELECT COLUMN_NAME AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".pSQL($t)."'")?:[]; $o=[]; foreach($r as $x)$o[strtolower((string)$x['c'])]=true; return $o; }
function first(array $c,array $opts,$fb=null){ foreach($opts as $o){ if(isset($c[strtolower($o)])) return $o; } return $fb; }

$domain  = Tools::getShopDomainSsl(true);
$baseUrl = rtrim($domain,'/').rtrim(__PS_BASE_URI__ ?? '/','/');
$pdfjsOk = is_file(_PS_MODULE_DIR_.'lrofileupload/admin/pdfjs/web/viewer.html');
$pdfjsUrl= $baseUrl.'/modules/lrofileupload/admin/pdfjs/web/viewer.html';

/* CSRF */
if (session_status()!==PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
$CSRF=$_SESSION['csrf_token'];

/* Pull uploads WITHOUT optional joins */
$T = $P.'lrofileupload_uploads';
$rows=[]; $err=null;
if(tableExists($db,$T)){
  $c = cols($db,$T);
  $pk = first($c,['id_upload','upload_id','file_id','id'],'id_upload');
  $fn = first($c,['file_name','filename','original_name','name'],'file_name');
  $st = first($c,['status','state'],'status');
  $ts = first($c,['uploaded_at','date_uploaded','date_add','created_at','created','ts'],'uploaded_at');
  $cu = first($c,['id_customer','customer_id','customer'],'id_customer');
  $gr = first($c,['id_group','group_id','gid'],null);

  $relGrp = $gr ? "COALESCE(u.`{$gr}`,0)" : "0";
  $rel    = "CONCAT('customer_', u.`{$cu}`, '/group_', {$relGrp}, '/', u.`{$fn}`)";

  try{
    $rows = $db->executeS("
      SELECT u.`{$pk}` AS id_upload, u.`{$cu}` AS id_customer, ".($gr? "u.`{$gr}`":"NULL")." AS id_group,
             u.`{$fn}` AS file_name, u.`{$st}` AS status, u.`{$ts}` AS uploaded_at,
             {$rel} AS rel_path
        FROM `{$T}` u
       ORDER BY u.`{$ts}` DESC, u.`{$pk}` DESC
       LIMIT 1000
    ") ?: [];
  }catch(Throwable $e){ $err=$e->getMessage(); }
}

?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><title>Uploads (simple)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= h($CSRF) ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>body{background:#f7f9fc}</style>
</head><body class="p-3">
<?php if (file_exists(__DIR__.'/nav.php')) include __DIR__.'/nav.php'; ?>
<h3>Uploads (simple)</h3>

<?php if ($err): ?><div class="alert alert-danger">SQL error: <?= h($err) ?></div><?php endif; ?>
<?php if (!$rows): ?><div class="alert alert-info">No uploads found (or table missing).</div><?php endif; ?>

<div class="table-responsive">
<table class="table table-sm table-striped align-middle">
  <thead class="table-light">
    <tr><th>ID</th><th>Customer</th><th>Group</th><th>File</th><th>Uploaded</th><th>Status</th><th class="text-end">Actions</th></tr>
  </thead>
  <tbody>
  <?php foreach($rows as $r):
    $rel = (string)($r['rel_path'] ?? '');
    $fn  = (string)($r['file_name'] ?? '');
    $serve = $baseUrl.'/modules/lrofileupload/admin/serve_file_safe.php?file='.rawurlencode($rel);
    $isPdf = (bool)preg_match('~\.pdf$~i',$fn);
    $isImg = (bool)preg_match('~\.(jpe?g|png|gif|webp)$~i',$fn);
    $pv    = $isImg ? $serve : ($isPdf && $pdfjsOk ? ($pdfjsUrl.'?file='.rawurlencode($serve)) : $serve);
  ?>
    <tr>
      <td><?= (int)$r['id_upload'] ?></td>
      <td>#<?= (int)$r['id_customer'] ?></td>
      <td><?= isset($r['id_group']) ? (int)$r['id_group'] : 0 ?></td>
      <td class="text-break"><?= h($fn) ?></td>
      <td><?= h((string)$r['uploaded_at']) ?></td>
      <td><?= h((string)$r['status']) ?></td>
      <td class="text-end">
        <a class="btn btn-sm btn-outline-info" target="_blank" rel="noopener" href="<?= h($pv) ?>">Preview</a>
        <a class="btn btn-sm btn-success" href="<?= h($serve) ?>&download=1">Download</a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<p class="mt-3"><a class="btn btn-outline-secondary" href="diag_uploads.php" target="_blank">Run diagnostics</a></p>
</body></html>
