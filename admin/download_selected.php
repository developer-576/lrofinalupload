<?php
/** modules/lrofileupload/admin/download_selected.php */
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';
if (session_status()===PHP_SESSION_NONE) session_start();
if (function_exists('lro_require_admin')) lro_require_admin(false);
if (function_exists('lro_headers_no_cache')) lro_headers_no_cache();

if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf_token'])) {
  http_response_code(403); exit('CSRF');
}

$files = json_decode((string)($_POST['files_json'] ?? '[]'), true);
if (!is_array($files) || !$files) { http_response_code(400); exit('No files'); }

function lro_storage_roots(): array {
  $roots = [];
  if (defined('LRO_STORAGE_BASE')) { $rp=realpath((string)LRO_STORAGE_BASE); if($rp && is_dir($rp)) $roots[] = rtrim($rp,'/'); }
  if (class_exists('Configuration')) { $cfg=(string)Configuration::get('LRO_STORAGE_BASE'); if($cfg){ $rp=realpath($cfg); if($rp && is_dir($rp)) $roots[] = rtrim($rp,'/'); } }
  foreach (['/home/mfjprqzu/uploads_lrofileupload', _PS_ROOT_DIR_.'/../uploads_lrofileupload', _PS_MODULE_DIR_.'lrofileupload/uploads'] as $c){
    $rp=realpath($c); if($rp && is_dir($rp) && !in_array(rtrim($rp,'/'),$roots,true)) $roots[] = rtrim($rp,'/');
  }
  return $roots;
}

$valid = [];
foreach ($files as $rel) {
  $rel = ltrim(str_replace('\\','/',(string)$rel), '/');
  if (!preg_match('#^customer_\d+/(group_\d+/)?[^/]+$#', $rel)) continue;

  $full = null;
  foreach (lro_storage_roots() as $root) {
    $try = realpath($root.'/'.$rel);
    if ($try && str_starts_with($try,$root) && is_file($try)) { $full=$try; break; }
    if (preg_match('#^customer_(\d+)/group_\d+/(.+)$#', $rel, $m)) {
      $legacy = realpath($root.'/customer_'.$m[1].'/'.$m[2]);
      if ($legacy && str_starts_with($legacy,$root) && is_file($legacy)) { $full=$legacy; break; }
    }
  }
  if ($full) $valid[$rel] = $full;
}
if (!$valid) { http_response_code(404); exit('Files missing'); }

$tmp = tempnam(sys_get_temp_dir(), 'lrozip_');
$zip = new ZipArchive();
$zip->open($tmp, ZipArchive::OVERWRITE);
foreach ($valid as $rel => $full) { $zip->addFile($full, basename($rel)); }
$zip->close();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="documents.zip"');
header('Content-Length: '.filesize($tmp));
readfile($tmp);
@unlink($tmp);
