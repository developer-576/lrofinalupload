<?php
/* modules/lrofileupload/admin/tools/fix_upload_locations.php */
declare(strict_types=1);
@ini_set('display_errors','0'); error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE);
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__.'/../_bootstrap.php';
if (session_status()!==PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['admin_id'])) { http_response_code(403); exit("Forbidden\n"); }

$moduleRoot  = dirname(__DIR__, 1);                 // /modules/lrofileupload/admin -> /modules/lrofileupload
$uploadsRoot = realpath($moduleRoot.'/uploads');    // physical uploads root
if (!$uploadsRoot) exit("uploads/ not found\n");

$db  = Db::getInstance();
$tbl = _DB_PREFIX_.'lrofileupload_uploads';

/* map: customer -> (filename -> group) */
$rows = $db->executeS("SELECT id_customer, id_group, file_name FROM `$tbl`");
$map  = [];
foreach ($rows as $r) {
  $cid = (int)$r['id_customer']; $gid = (int)$r['id_group']; $fn = (string)$r['file_name'];
  $map[$cid][$fn] = $gid;
}

$moved=0; $skipped=0;
foreach (glob($uploadsRoot.'/customer_*', GLOB_ONLYDIR) as $custDir) {
  if (!preg_match('~/customer_(\d+)$~', $custDir, $m)) continue;
  $cid = (int)$m[1];

  foreach (glob($custDir.'/*') as $path) {
    if (is_dir($path)) continue;                 // already in a subfolder
    $fn = basename($path);

    if (!isset($map[$cid][$fn])) { $skipped++; continue; }  // no DB row
    $gid = (int)$map[$cid][$fn];

    $destDir = $custDir.'/group_'.$gid;
    if (!is_dir($destDir)) @mkdir($destDir, 0755, true);

    $dest = $destDir.'/'.$fn;
    if (@rename($path, $dest)) { $moved++; }
    else { $skipped++; }
  }
}

echo "Moved: $moved\nSkipped: $skipped\nDone.\n";
