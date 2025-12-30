<?php
/**************************************************
 * modules/lrofileupload/admin/serve_file.php
 * Secure preview/download for psfc_lrofileupload_uploads
 *
 * Usage:
 *   serve_file.php?id_upload=56               (inline preview by default)
 *   serve_file.php?id_upload=56&inline=0      (force download)
 *   serve_file.php?id_upload=56&debug=1       (admin-only debug)
 **************************************************/
declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors','0');
ini_set('display_startup_errors','0');

/* ---------- PS bootstrap ---------- */
$HERE  = __DIR__;
$ROOT  = realpath($HERE . '/../../..');
$bootA = $HERE.'/_bootstrap.php';
$bootB = $ROOT.'/config/config.inc.php';
if (is_file($bootA))      require_once $bootA;
elseif (is_file($bootB))  require_once $bootB;
else { http_response_code(500); exit('Bootstrap failed'); }

if (session_status() === PHP_SESSION_NONE) session_start();

/* ---------- Admin guard (strict) ---------- */
if (function_exists('lro_require_admin')) {
  lro_require_admin(false);
} elseif (function_exists('require_admin_login')) {
  require_admin_login(false);
} elseif (empty($_SESSION['admin_id'])) {
  http_response_code(403);
  exit('Forbidden (admins only)');
}

/* ---------- Helpers ---------- */
function send400($m='Bad request'){ http_response_code(400); exit($m); }
function send404($m='Not found'){ http_response_code(404); exit($m); }
function send500($m='Server error'){ http_response_code(500); exit($m); }

function real_in_allowed(string $path, array $allowedBases): bool {
  $rp = realpath($path);
  if (!$rp) return false;
  $rp = rtrim($rp, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
  foreach ($allowedBases as $base) {
    if (str_starts_with($rp, $base)) return true;
  }
  return false;
}

/* ---------- Inputs ---------- */
// Default to inline for preview; allow &inline=0 to force download
$inline = (int)($_GET['inline'] ?? 1);
$debug  = (int)($_GET['debug']  ?? 0);

$id = null;
foreach (['id_upload','file_id','id','upload_id'] as $k) {
  if (isset($_GET[$k]) && is_numeric($_GET[$k])) { $id = (int)$_GET[$k]; break; }
}
if (!$id) send400('Missing id_upload');

/* ---------- DB ---------- */
$db     = Db::getInstance();
$prefix = _DB_PREFIX_;
$tbl    = $prefix.'lrofileupload_uploads';

$sql = "SELECT * FROM `{$tbl}` WHERE `id_upload` = ".(int)$id;

try {
  $row = $db->getRow($sql);
} catch (Exception $e) {
  if ($debug) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "DB ERROR: ".$e->getMessage()."\n\nSQL:\n".$sql."\n";
    exit;
  }
  send500('Internal Server Error');
}
if (!$row) send404('Row not found');

/* ---------- Resolve filesystem path ---------- */
$cid     = (int)($row['id_customer'] ?? 0);
$gid     = (int)($row['id_group'] ?? 0);
$rid     = (int)($row['id_requirement'] ?? 0);
$fnameDb = (string)($row['file_name'] ?? '');
$origDb  = (string)($row['original_name'] ?? '');

/* Allowed bases (order matters) */
$BASES_RAW = [
  '/home/mfjprqzu/uploads_lrofileupload',             // preferred (outside web root)
  '/home/mfjprqzu/public_html/uploads_lrofileupload', // legacy
];
$ALLOWED = [];
foreach ($BASES_RAW as $B) {
  $r = realpath($B);
  $ALLOWED[] = rtrim($r ?: $B, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
}

/* 0) If DB stored an absolute path INSIDE allowed bases, use it */
$absFirst = null;
foreach ([$fnameDb, $origDb] as $cand) {
  if ($cand && $cand[0] === '/' && real_in_allowed($cand, $ALLOWED)) {
    $absFirst = realpath($cand);
    break;
  }
}
if ($absFirst && is_file($absFirst)) {
  $chosen = $absFirst;
} else {
  /* Build candidate list deterministically */
  $candidates = [];
  $names = [];
  foreach ([$fnameDb, $origDb] as $n) {
    if (!$n) continue;
    $names[] = basename($n); // strip paths
  }
  $names = array_values(array_unique(array_filter($names)));
  if (!$names) send404('No filename available');

  foreach ($names as $name) {
    foreach ($ALLOWED as $B) {
      // canonical
      if ($cid && $gid && $rid) {
        $candidates[] = $B."customer_{$cid}/g{$gid}/r{$rid}/{$name}";
      }
      // legacy (no requirement subfolder)
      if ($cid && $gid) {
        $candidates[] = $B."customer_{$cid}/g{$gid}/{$name}";
        $candidates[] = $B."customer_{$cid}/group_{$gid}/{$name}";
      }
      if ($cid) {
        $candidates[] = $B."customer_{$cid}/{$name}";
        if ($gid) $candidates[] = $B."{$cid}/{$gid}/{$name}"; // very old layout
      }
      // flat last resort
      $candidates[] = $B.$name;
    }
  }

  $chosen = null;
  foreach (array_unique($candidates) as $cand) {
    $real = realpath($cand);
    if ($real && is_file($real) && real_in_allowed($real, $ALLOWED)) { $chosen = $real; break; }
  }

  /* Final fallback: recursive search inside customer's folder by basename */
  if (!$chosen && $cid) {
    foreach ($ALLOWED as $B) {
      $root = $B."customer_{$cid}";
      if (!is_dir($root)) continue;
      try {
        $it = new RecursiveIteratorIterator(
          new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
          RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $fi) {
          if (!$fi->isFile()) continue;
          foreach ($names as $bn) {
            if ($fi->getFilename() === $bn) {
              $real = $fi->getRealPath();
              if ($real && real_in_allowed($real, $ALLOWED)) { $chosen = $real; break 3; }
            }
          }
        }
      } catch (Throwable $e) { /* ignore */ }
    }
  }
}

if (!$chosen) {
  if ($debug) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "DEBUG: file not found on disk\n\n";
    echo "SQL:\n".$sql."\n\n";
    echo "id=$id  cid=$cid  gid=$gid  rid=$rid\n";
    echo "file_name=$fnameDb\n";
    echo "original_name=$origDb\n\n";
    echo "Allowed bases:\n - ".implode("\n - ", $ALLOWED)."\n";
    exit;
  }
  send404('File not found');
}

/* ---------- Stream ---------- */
$downloadName = $origDb ?: basename($chosen);
$downloadName = str_replace(["\r","\n"], '', $downloadName); // sanitize

// --- MIME detection with fallback map ---
$ext = strtolower(pathinfo($chosen, PATHINFO_EXTENSION));
$mime = null;
if (class_exists('finfo')) {
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime  = $finfo->file($chosen) ?: null;
}
if (!$mime || $mime === 'application/octet-stream') {
  // minimal, safe map for common types you preview
  $map = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg', 'jpe' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'bmp'  => 'image/bmp',
    'tif'  => 'image/tiff', 'tiff' => 'image/tiff',
    'svg'  => 'image/svg+xml',
  ];
  $mime = $map[$ext] ?? 'application/octet-stream';
}

// Clean output buffers before streaming
while (ob_get_level() > 0) { @ob_end_clean(); }

// Security/compat headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Inline for preview; attachment for download
$disposition = $inline ? 'inline' : 'attachment';

header('Content-Type: '.$mime);
header('Content-Length: '.filesize($chosen));
$base = basename($downloadName);
header('Content-Disposition: '.$disposition.'; filename="'.addslashes($base).'"' .
       '; filename*=UTF-8\'\''.rawurlencode($downloadName));

// Stream the file
$fp = fopen($chosen, 'rb');
if ($fp === false) send500('Unable to open file');
while (!feof($fp)) {
  echo fread($fp, 8192);
  flush();
}
fclose($fp);
exit;
