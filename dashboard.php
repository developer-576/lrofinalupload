<?php
declare(strict_types=1);

/**
 * /modules/lrofileupload/admin/dashboard.php
 * Standard Admin Dashboard (not master-only).
 */

require_once __DIR__ . '/_bootstrap.php';   // PS bootstrap + session + helpers

/* ---- Auth ---- */
if (function_exists('lro_require_admin')) {
    lro_require_admin(false); // not master-only
} elseif (function_exists('require_admin_login')) {
    require_admin_login(false);
} else {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['admin_id'])) {
        http_response_code(403);
        exit('Forbidden (admins only)');
    }
}
if (function_exists('require_cap')) require_cap('can_view_dashboard');

if (function_exists('admin_log')) {
    admin_log('view:dashboard', [
        'admin_id' => $_SESSION['admin_id'] ?? null,
        'ip'       => $_SERVER['REMOTE_ADDR'] ?? null,
        'ua'       => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);
}

/* ---- Helpers ---- */
$PREFIX      = defined('_DB_PREFIX_') ? _DB_PREFIX_ : 'ps_';
$TBL_UPLOADS = $PREFIX . 'lrofileupload_uploads';
$IS_MASTER   = !empty($_SESSION['lro_is_master']) || !empty($_SESSION['is_master']);

$hasCap = function (string $cap) use ($IS_MASTER): bool {
    if (function_exists('lro_has_cap')) return (bool) lro_has_cap($cap);
    return $IS_MASTER || !empty($_SESSION[$cap]) || !empty($_SESSION['lro_' . $cap]);
};

$psDb = class_exists('Db') ? Db::getInstance() : null;

$getValue = function (string $sql) use ($psDb) {
    if ($psDb) return (int)$psDb->getValue($sql);
    throw new RuntimeException('Db adapter missing');
};
$getRows = function (string $sql) use ($psDb) {
    if ($psDb) return (array)$psDb->executeS($sql);
    throw new RuntimeException('Db adapter missing');
};
$colExists = function (string $table, string $col) use ($psDb): bool {
    try {
        return (bool)$psDb->getValue("SHOW COLUMNS FROM `$table` LIKE '".pSQL($col)."'");
    } catch (Throwable $e) {
        return false;
    }
};

/* ---- Check table ---- */
$tableExists = true;
try {
    $psDb->getValue("SELECT COUNT(*) FROM `$TBL_UPLOADS`");
} catch (Throwable $e) {
    $tableExists = false;
}

/* ---- Stats ---- */
$stats = ['total'=>0,'pending'=>0,'approved'=>0,'rejected'=>0];
$recent = [];

if ($tableExists) {
    $stats['total']    = $getValue("SELECT COUNT(*) FROM `$TBL_UPLOADS`");
    $stats['pending']  = $getValue("SELECT COUNT(*) FROM `$TBL_UPLOADS` WHERE LOWER(status)='pending'");
    $stats['approved'] = $getValue("SELECT COUNT(*) FROM `$TBL_UPLOADS` WHERE LOWER(status)='approved'");
    $stats['rejected'] = $getValue("SELECT COUNT(*) FROM `$TBL_UPLOADS` WHERE LOWER(status)='rejected'");

    // ---- Column auto-detect + aliasing (so template can use file_id/original_name) ----
    $ID_COLS   = ['id_upload','file_id','id','id_file'];
    $NAME_COLS = ['file_name','original_name','filename','name'];
    $COL_ID = null;
    foreach ($ID_COLS as $c)  { if ($colExists($TBL_UPLOADS, $c))  { $COL_ID = $c; break; } }
    if (!$COL_ID)  $COL_ID  = 'id_upload'; // safe default (won't crash if absent due to aliasing below)
    $COL_NM = null;
    foreach ($NAME_COLS as $c){ if ($colExists($TBL_UPLOADS, $c)) { $COL_NM = $c; break; } }
    if (!$COL_NM) $COL_NM = 'file_name';

    $recent = $getRows("
        SELECT `$COL_ID`   AS file_id,
               `$COL_NM`   AS original_name,
               status
        FROM `$TBL_UPLOADS`
        ORDER BY `$COL_ID` DESC
        LIMIT 10
    ");
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Uploads Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { background:#f7f9fc; }
    .page-wrap { max-width: 1100px; }
    .card-metric { border:0; border-radius:1rem; box-shadow:0 6px 18px rgba(0,0,0,.06); }
    .metric-value { font-size: 1.8rem; font-weight:700; }
    .metric-label { color:#6c757d; font-size:.95rem; }
    .list-group a { display:flex; align-items:center; gap:.5rem; }
    .pill { font-weight:600; }
  </style>
</head>
<body>
<div class="container py-3 page-wrap">
  <?php if (file_exists(__DIR__.'/nav.php')) include __DIR__.'/nav.php'; ?>

  <div class="d-flex align-items-center justify-content-between mt-2 mb-3">
    <h3 class="mb-0">Dashboard</h3>
    <?php if ($IS_MASTER): ?>
      <span class="badge text-bg-primary pill"><i class="bi bi-shield-lock"></i> Master</span>
    <?php else: ?>
      <span class="badge text-bg-secondary pill"><i class="bi bi-person-badge"></i> Admin</span>
    <?php endif; ?>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="card card-metric"><div class="card-body">
      <div class="metric-value"><?= (int)$stats['total'] ?></div><div class="metric-label">Total uploads</div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card card-metric"><div class="card-body">
      <div class="metric-value"><?= (int)$stats['pending'] ?></div><div class="metric-label">Pending review</div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card card-metric"><div class="card-body">
      <div class="metric-value"><?= (int)$stats['approved'] ?></div><div class="metric-label">Approved</div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card card-metric"><div class="card-body">
      <div class="metric-value"><?= (int)$stats['rejected'] ?></div><div class="metric-label">Rejected</div>
    </div></div></div>
  </div>

  <div class="card mb-4">
    <div class="card-header bg-white"><strong>Quick Actions</strong></div>
    <div class="list-group list-group-flush">
      <?php if ($hasCap('can_view_uploads')): ?>
        <a class="list-group-item list-group-item-action" href="view_uploads.php"><i class="bi bi-file-earmark-text"></i> View Uploads</a>
      <?php endif; ?>
      <?php if ($hasCap('can_view_credential_card')): ?>
        <a class="list-group-item list-group-item-action" href="credential_card_viewer.php"><i class="bi bi-person-vcard"></i> Credential Cards</a>
      <?php endif; ?>
      <a class="list-group-item list-group-item-action" href="logs_unified.php"><i class="bi bi-clipboard-data"></i> Unified Logs</a>
      <?php if ($IS_MASTER): ?>
        <a class="list-group-item list-group-item-action" href="master_dashboard.php"><i class="bi bi-speedometer2"></i> Master Dashboard</a>
        <a class="list-group-item list-group-item-action" href="manage_admins.php"><i class="bi bi-people"></i> Manage Admins</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
      <strong>Recent uploads</strong>
      <?php if ($hasCap('can_view_uploads')): ?>
        <a class="btn btn-sm btn-outline-primary" href="view_uploads.php"><i class="bi bi-arrow-right-square"></i> Open viewer</a>
      <?php endif; ?>
    </div>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light">
          <tr><th style="width:90px;">File #</th><th>Original name</th><th style="width:120px;">Status</th></tr>
        </thead>
        <tbody>
        <?php if (!$tableExists): ?>
          <tr><td colspan="3" class="text-muted">Uploads table not found (<?= htmlspecialchars($TBL_UPLOADS) ?>).</td></tr>
        <?php elseif (empty($recent)): ?>
          <tr><td colspan="3" class="text-muted">No uploads yet.</td></tr>
        <?php else: foreach ($recent as $row):
          $status = (string)($row['status'] ?? '');
          $badge  = ($status==='approved')?'success':(($status==='pending')?'warning':(($status==='rejected')?'danger':'secondary'));
        ?>
          <tr>
            <td>#<?= (int)$row['file_id'] ?></td>
            <td><?= htmlspecialchars($row['original_name'] ?? '(unnamed)') ?></td>
            <td><span class="badge text-bg-<?= $badge ?>"><?= htmlspecialchars($status ?: 'unknown') ?></span></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
