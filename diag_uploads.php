<?php
// modules/lrofileupload/admin/diag_uploads.php
declare(strict_types=1);

ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

/* ---- Bootstrap PrestaShop ---- */
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
  if (file_exists($root.'/config/config.inc.php') && file_exists($root.'/init.php')) {
    require_once $root.'/config/config.inc.php';
    require_once $root.'/init.php';
    return;
  }
  header('Content-Type: text/plain; charset=utf-8', true, 500);
  exit("Cannot bootstrap PrestaShop.");
})();

/* ---- Admin auth (lenient, but requires admin) ---- */
require_once __DIR__.'/session_bootstrap.php';
if (function_exists('lro_require_admin')) lro_require_admin(false);
elseif (function_exists('require_admin_login')) require_admin_login(false);
else { session_start(); if (empty($_SESSION['admin_id'])) { http_response_code(403); exit('Admins only'); } }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }
$db = Db::getInstance();
$P  = _DB_PREFIX_;

function tableExists(Db $db, string $t): bool {
  $sql="SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".pSQL($t)."'";
  return (int)$db->getValue($sql) > 0;
}
function cols(Db $db, string $t): array {
  if (!tableExists($db,$t)) return [];
  $rows = $db->executeS("SELECT COLUMN_NAME AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".pSQL($t)."'") ?: [];
  return array_map(fn($r)=> (string)$r['c'], $rows);
}

/* ---- Detect storage bases ---- */
$bases = [];
if (defined('_PS_UPLOAD_DIR_')) $bases[] = rtrim(_PS_UPLOAD_DIR_, '/').'/lrofileupload';
if (defined('_PS_MODULE_DIR_')) $bases[] = rtrim(_PS_MODULE_DIR_, '/').'/lrofileupload/storage';
if (defined('_PS_ROOT_DIR_'))   $bases[] = rtrim(_PS_ROOT_DIR_, '/').'/upload/lrofileupload';

$existingBases = array_values(array_filter($bases, fn($p)=>is_dir($p)));

/* ---- Uploads table & rows ---- */
$T = $P.'lrofileupload_uploads';
$exists = tableExists($db,$T);
$columns = $exists ? cols($db,$T) : [];

$sample = [];
$err = null;
if ($exists) {
  try {
    $sample = $db->executeS("SELECT * FROM `{$T}` ORDER BY 1 DESC LIMIT 20") ?: [];
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

/* ---- Make guesses for rel path for first row ---- */
function guess_rel(array $row): ?string {
  foreach (['id_customer','customer_id','customer'] as $c) if (isset($row[$c])) { $cid=(int)$row[$c]; break; }
  if (!isset($cid)) return null;
  foreach (['id_group','group_id','gid'] as $g) { $gid = isset($row[$g]) ? (int)$row[$g] : 0; break; }
  foreach (['file_name','filename','original_name','name'] as $f) if (!empty($row[$f])) { $fn=(string)$row[$f]; break; }
  if (empty($fn)) return null;
  return "customer_{$cid}/group_".($gid ?: 0)."/".$fn;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html><head>
<meta charset="utf-8"><title>Diag: uploads</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>body{background:#f7f9fc}</style>
</head><body class="p-3">
<h3>Diagnostics — lrofileupload</h3>

<div class="card mb-3"><div class="card-body">
  <h5>Storage bases checked</h5>
  <ul class="mb-0">
    <?php foreach ($bases as $b): ?>
      <li><?= h($b) ?> <?= is_dir($b) ? '<span class="badge bg-success">exists</span>' : '<span class="badge bg-danger">missing</span>' ?></li>
    <?php endforeach; ?>
  </ul>
</div></div>

<div class="card mb-3"><div class="card-body">
  <h5>Uploads table</h5>
  <p><code><?= h($T) ?></code> : <?= $exists ? '<span class="badge bg-success">exists</span>' : '<span class="badge bg-danger">missing</span>' ?></p>
  <?php if ($exists): ?>
    <p>Columns (<?= count($columns) ?>): <code><?= h(implode(', ', $columns)) ?></code></p>
    <?php if ($err): ?><div class="alert alert-danger">Query error: <?= h($err) ?></div><?php endif; ?>
    <div class="table-responsive">
      <table class="table table-sm table-striped">
        <thead><tr>
          <?php foreach (array_keys($sample[0] ?? ['#'=>'#']) as $c): ?><th><?= h($c) ?></th><?php endforeach; ?>
        </tr></thead>
        <tbody>
          <?php foreach ($sample as $r): ?><tr>
            <?php foreach ($r as $v): ?><td class="text-break"><?= h((string)$v) ?></td><?php endforeach; ?>
          </tr><?php endforeach; if (!$sample): ?>
          <tr><td class="text-muted" colspan="12">No rows.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($sample): 
      $rel = guess_rel($sample[0]); ?>
      <h6 class="mt-3">Rel-path guess for first row</h6>
      <p><?= $rel ? '<code>'.h($rel).'</code>' : '<em>Could not guess (missing id_customer/file_name)</em>' ?></p>
      <?php if ($rel): ?>
        <ul>
          <?php foreach ($existingBases as $b): 
            $p = rtrim($b,'/').'/'.$rel; ?>
            <li><?= h($p) ?> —
              <?php if (is_file($p)): ?>
                <span class="badge bg-success">FOUND (<?= number_format(filesize($p)) ?> bytes)</span>
                — <a class="btn btn-sm btn-outline-primary" target="_blank" href="serve_file_safe.php?file=<?= rawurlencode($rel) ?>&debug=1">open via server (debug)</a>
              <?php else: ?>
                <span class="badge bg-danger">not found</span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    <?php endif; ?>
</div></div>

<div class="alert alert-info">
  If the file is “not found” under every base above, the storage folder location is different.
  Tell me the correct folder and I’ll lock it into the file server.
</div>

</body></html>
