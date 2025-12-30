<?php
// modules/lrofileupload/admin/_doctor.php
declare(strict_types=1);

/* ---------------- Bootstrap PrestaShop ---------------- */
(function () {
  ini_set('display_errors','1'); ini_set('display_startup_errors','1'); error_reporting(E_ALL);
  $d = __DIR__;
  for ($i = 0; $i < 8; $i++) {
    if (file_exists($d.'/config/config.inc.php') && file_exists($d.'/init.php')) {
      require_once $d.'/config/config.inc.php'; require_once $d.'/init.php'; return;
    }
    $d = dirname($d);
  }
  $root = dirname(__DIR__, 3);
  if (file_exists($root.'/config/config.inc.php') && file_exists($root.'/init.php')) {
    require_once $root.'/config/config.inc.php'; require_once $root.'/init.php'; return;
  }
  header('Content-Type: text/plain; charset=utf-8', true, 500);
  exit("Could not bootstrap PrestaShop.");
})();

/* ---------------- Admin gate ---------------- */
require_once __DIR__.'/session_bootstrap.php';
if (function_exists('lro_require_admin')) lro_require_admin(false);
elseif (function_exists('require_admin_login')) require_admin_login(false);
else { if (session_status()!==PHP_SESSION_ACTIVE) session_start();
  if (empty($_SESSION['admin_id'])) { http_response_code(403); exit('Forbidden (admins only)'); }}

/* ---------------- Helpers ---------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ok($b){ return $b ? '<span class="ok">OK</span>' : '<span class="bad">FAIL</span>'; }
function yn($b){ return $b ? '<span class="ok">Yes</span>' : '<span class="bad">No</span>'; }
$db = Db::getInstance(); $P = _DB_PREFIX_;
function tableExists(Db $db,string $t):bool{
  return (int)$db->getValue("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".pSQL($t)."'")>0;
}
function cols(Db $db,string $t):array{
  if (!tableExists($db,$t)) return [];
  $rows = $db->executeS("SELECT COLUMN_NAME c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".pSQL($t)."'") ?: [];
  return array_map(fn($r)=>$r['c'],$rows);
}
function firstExisting(array $all, array $opts, ?string $fallback=null): ?string {
  $set = array_change_key_case(array_flip($all), CASE_LOWER);
  foreach ($opts as $o) if (isset($set[strtolower($o)])) return $o;
  return $fallback;
}

/* ---------------- Environment ---------------- */
$env = [
  'php'       => PHP_VERSION,
  'fileinfo'  => extension_loaded('fileinfo'),
  'mbstring'  => extension_loaded('mbstring'),
  'json'      => extension_loaded('json'),
  'gd'        => extension_loaded('gd'),
  'exif'      => extension_loaded('exif'),
  'upload_max_filesize' => ini_get('upload_max_filesize'),
  'post_max_size'       => ini_get('post_max_size'),
  'memory_limit'        => ini_get('memory_limit'),
];

/* ---------------- Storage base candidates ---------------- */
$cands=[];
if (defined('_PS_UPLOAD_DIR_')) $cands[] = rtrim(_PS_UPLOAD_DIR_,'/').'/lrofileupload';
if (defined('_PS_MODULE_DIR_')) $cands[] = rtrim(_PS_MODULE_DIR_,'/').'/lrofileupload/storage';
if (defined('_PS_ROOT_DIR_'))   $cands[] = rtrim(_PS_ROOT_DIR_,'/').'/upload/lrofileupload';
$storage = ''; $candInfo=[];
foreach ($cands as $c) {
  $exists = is_dir($c); $w = $exists ? is_writable($c) : false;
  $candInfo[] = [$c,$exists,$w];
  if ($storage==='' && $exists) $storage=$c;
}

/* ---------------- Files that should exist ---------------- */
$baseUrl = rtrim(Tools::getShopDomainSsl(true), '/') . rtrim(__PS_BASE_URI__ ?? '/', '/');
$paths = [
  'serve_file_safe.php'      => __DIR__.'/serve_file_safe.php',
  'view_uploads.php'         => __DIR__.'/view_uploads.php',
  'logs_unified.php'         => __DIR__.'/logs_unified.php',
  'credential_card_viewer.php'=> __DIR__.'/credential_card_viewer.php',
  'pdfjs viewer'             => _PS_MODULE_DIR_.'lrofileupload/admin/pdfjs/web/viewer.html',
];
$existsPaths = [];
foreach ($paths as $k=>$p) $existsPaths[$k] = file_exists($p);

/* ---------------- Tables to check ---------------- */
$tables = [
  'uploads'          => $P.'lrofileupload_uploads',
  'groupsA'          => $P.'lrofileupload_product_groups',
  'groupsB'          => $P.'lrofileupload_groups',
  'reasonsA'         => $P.'lrofileupload_reasons',
  'reasonsB'         => $P.'lrofileupload_rejection_reasons',
  'action_logs'      => $P.'lrofileupload_action_logs',
  'manual_unlocks'   => $P.'lrofileupload_manual_unlocks',
  'group_requirements'=> $P.'lrofileupload_group_requirements',
  'doc_requirements' => $P.'lrofileupload_document_requirements',
  'requirements'     => $P.'lrofileupload_requirements',
];
$tinfo=[];
foreach ($tables as $label=>$t) {
  $has = tableExists($db,$t);
  $tinfo[$label] = ['name'=>$t,'exists'=>$has,'cols'=>$has?cols($db,$t):[]];
}

/* ---------------- Sample upload row & file resolution ---------------- */
$sample = null; $sample_err = '';
if ($tinfo['uploads']['exists']) {
  try {
    $cs = $tinfo['uploads']['cols'];
    $pk   = firstExisting($cs, ['id_upload','upload_id','file_id','id'], 'id_upload');
    $fn   = firstExisting($cs, ['file_name','filename','original_name','name'], 'file_name');
    $cu   = firstExisting($cs, ['id_customer','customer_id','customer'], 'id_customer');
    $gr   = firstExisting($cs, ['id_group','group_id','gid'], null);
    $ts   = firstExisting($cs, ['uploaded_at','date_uploaded','date_add','created_at','created','ts'], 'uploaded_at');

    $sample = $db->getRow("SELECT `{$pk}` AS id_upload, `{$fn}` AS file_name, `{$cu}` AS id_customer, ".
                          ($gr ? "`{$gr}` AS id_group" : "NULL AS id_group").
                          ", `{$ts}` AS uploaded_at FROM `".$tables['uploads']."` ORDER BY `{$ts}` DESC, `{$pk}` DESC LIMIT 1");
    if ($sample) {
      $rel = 'customer_'.(int)$sample['id_customer'].'/group_'.(int)($sample['id_group']??0).'/'.(string)$sample['file_name'];
      $sample['rel_path'] = $rel;
      $sample['abs_path'] = $storage ? rtrim($storage,'/').'/'.$rel : '';
      $sample['exists']   = $sample['abs_path'] && is_file($sample['abs_path']);
      $sample['serve']    = $baseUrl.'/modules/lrofileupload/admin/serve_file_safe.php?file='.rawurlencode($rel);
    }
  } catch (Throwable $e) {
    $sample_err = $e->getMessage();
  }
}

/* ---------------- CSRF status ---------------- */
if (session_status()!==PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf_token'];

/* ---------------- Render ---------------- */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>LRO Module Doctor</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root { --ok:#166534; --bad:#b91c1c; --muted:#6b7280; }
  body{font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto,Arial;background:#f7f9fc;color:#0f172a;margin:0;padding:20px}
  h1{margin:.2rem 0 1rem;font-size:1.6rem} .muted{color:var(--muted)}
  .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;margin:12px 0;padding:14px 16px;box-shadow:0 6px 22px rgba(12,18,28,.06)}
  .row{display:flex;gap:18px;flex-wrap:wrap}
  .col{flex:1 1 320px}
  code{background:#f1f5f9;padding:2px 6px;border-radius:6px}
  ul{margin:.4rem 0 .2rem 1.25rem}
  .ok{color:var(--ok);font-weight:600}
  .bad{color:var(--bad);font-weight:600}
  table{width:100%;border-collapse:collapse}
  th,td{padding:8px 10px;border-bottom:1px solid #eef2f7;vertical-align:top}
  th{background:#f8fafc;text-align:left}
  .btn{display:inline-block;padding:.4rem .7rem;border:1px solid #d1d5db;border-radius:8px;text-decoration:none;color:#111;background:#fff}
  .btn:hover{background:#f8fafc}
</style>
</head>
<body>
<h1>LRO Module Doctor</h1>

<div class="row">
  <div class="col card">
    <h3>Environment</h3>
    <p>PHP: <strong><?= h($env['php']) ?></strong></p>
    <ul class="muted">
      <li>fileinfo: <?= yn($env['fileinfo']) ?></li>
      <li>mbstring: <?= yn($env['mbstring']) ?></li>
      <li>gd: <?= yn($env['gd']) ?>, exif: <?= yn($env['exif']) ?></li>
      <li>upload_max_filesize: <code><?= h($env['upload_max_filesize']) ?></code>,
          post_max_size: <code><?= h($env['post_max_size']) ?></code>,
          memory_limit: <code><?= h($env['memory_limit']) ?></code></li>
    </ul>
  </div>

  <div class="col card">
    <h3>Storage directory</h3>
    <p>Candidates (first existing wins):</p>
    <ul>
      <?php foreach ($candInfo as [$path,$exists,$w]): ?>
        <li><code><?= h($path) ?></code> — exists: <?= yn($exists) ?>, writable: <?= yn($w) ?></li>
      <?php endforeach; ?>
    </ul>
    <p><strong>Selected:</strong> <?= $storage ? '<code>'.h($storage).'</code>' : '<span class="bad">NONE FOUND</span>' ?></p>
    <?php if ($storage && !is_writable($storage)): ?>
      <p class="bad">Make the selected directory writable by PHP.</p>
    <?php elseif (!$storage): ?>
      <p class="bad">Create one of the candidate directories and put files under <code>customer_[id]/group_[gid]/filename</code>.</p>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h3>Endpoints present</h3>
  <ul>
    <?php foreach ($paths as $label=>$fs): ?>
      <li><?= h($label) ?> — <?= yn($existsPaths[$label]) ?> <?= $existsPaths[$label] ? '' : '<span class="bad"> (missing file: '.h($fs).')</span>' ?></li>
    <?php endforeach; ?>
  </ul>
  <p>
    <a class="btn" href="<?= h($baseUrl.'/modules/lrofileupload/admin/view_uploads.php') ?>">Open: view_uploads</a>
    <a class="btn" href="<?= h($baseUrl.'/modules/lrofileupload/admin/logs_unified.php') ?>">Open: logs_unified</a>
    <a class="btn" href="<?= h($baseUrl.'/modules/lrofileupload/admin/credential_card_viewer.php') ?>">Open: credential_card_viewer</a>
  </p>
</div>

<div class="card">
  <h3>Tables</h3>
  <table>
    <thead><tr><th>Label</th><th>Table</th><th>Exists</th><th>Columns (first 12)</th></tr></thead>
    <tbody>
      <?php foreach ($tinfo as $label => $ti): ?>
        <tr>
          <td><?= h($label) ?></td>
          <td><code><?= h($ti['name']) ?></code></td>
          <td><?= yn($ti['exists']) ?></td>
          <td class="muted"><?= $ti['exists'] ? h(implode(', ', array_slice($ti['cols'],0,12))) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$tinfo['uploads']['exists']): ?>
    <p class="bad">The uploads table is missing. The listing and approvals cannot work.</p>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Sample upload & file resolution</h3>
  <?php if (!$tinfo['uploads']['exists']): ?>
    <p class="bad">Cannot query sample without uploads table.</p>
  <?php elseif (!$sample): ?>
    <p class="bad">No rows found in uploads table<?= $sample_err?': '.h($sample_err):'.' ?></p>
  <?php else: ?>
    <p>Latest upload row:</p>
    <ul class="muted">
      <li>ID: <code><?= h((string)$sample['id_upload']) ?></code></li>
      <li>Customer: <code><?= h((string)$sample['id_customer']) ?></code>, Group: <code><?= h((string)($sample['id_group']??0)) ?></code></li>
      <li>Filename: <code><?= h((string)$sample['file_name']) ?></code></li>
      <li>Relative key: <code><?= h((string)$sample['rel_path']) ?></code></li>
      <li>Absolute path: <code><?= h((string)$sample['abs_path']) ?></code> — exists: <?= yn((bool)$sample['exists']) ?></li>
    </ul>
    <p>
      Serve link:
      <a class="btn" target="_blank" href="<?= h((string)$sample['serve']) ?>">Open in browser</a>
      <a class="btn" target="_blank" href="<?= h((string)$sample['serve'].'&download=1') ?>">Force download</a>
    </p>
    <?php if ($existsPaths['pdfjs viewer']): ?>
      <p>PDF.js detected. If the file is PDF, open via viewer:
        <a class="btn" target="_blank" href="<?= h($baseUrl.'/modules/lrofileupload/admin/pdfjs/web/viewer.html?file='.rawurlencode((string)$sample['serve'])) ?>">PDF.js preview</a>
      </p>
    <?php else: ?>
      <p class="muted">PDF.js viewer not found (optional).</p>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="card">
  <h3>CSRF/session</h3>
  <p>Session: <?= yn(session_status()===PHP_SESSION_ACTIVE) ?>, CSRF token present: <?= yn(!empty($CSRF)) ?></p>
  <p class="muted">Approve/Reject forms must include <code>csrf_token</code> equal to this session’s token.</p>
</div>

<div class="card">
  <h3>Actionable checklist</h3>
  <ol>
    <li>If <strong>Selected storage</strong> is “NONE” or not writable, create or fix permissions, then place files under
        <code>customer_[id]/group_[gid]/filename</code> inside that base.</li>
    <li>Open the <strong>Serve link</strong>. If it 404/403/500’s, focus on <code>serve_file_safe.php</code> and storage path.</li>
    <li>Ensure <strong>view_uploads.php</strong> exists (above) and loads. If rows appear but Approve/Reject don’t change status, verify
        <code>admin/approve_file.php</code> and <code>admin/reject_file.php</code> exist (the doctor doesn’t list them; add if missing) and that form field name is
        <code>file</code> (or <code>k</code>) carrying the relative key.</li>
    <li>If groups/reasons tables are missing, the UI will still work, but labels may be blank and “reasons” list will be limited.</li>
  </ol>
</div>

</body>
</html>
