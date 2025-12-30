<?php
/**************************************************
 * modules/lrofileupload/admin/master_dashboard.php
 **************************************************/
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/auth.php';

// MASTER-ONLY
require_admin_login(true);

// Audit
if (function_exists('admin_log')) {
    admin_log('view:master_dashboard', [
        'admin_id' => $_SESSION['admin_id'] ?? null,
        'ip'       => $_SERVER['REMOTE_ADDR'] ?? null,
        'ua'       => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);
}

$db     = Db::getInstance();
$prefix = _DB_PREFIX_;

function tblExists(Db $db, string $t): bool {
    try { $db->getValue("SELECT 1 FROM `$t` LIMIT 1"); return true; }
    catch (Throwable $e) { return false; }
}

$counts = ['admins'=>null, 'reasons'=>null, 'logs'=>null];
$recent = [];

/* Admins */
$t_admins = $prefix.'lrofileupload_admins';
if (tblExists($db, $t_admins)) {
    $counts['admins'] = (int)$db->getValue("SELECT COUNT(*) FROM `$t_admins`");
}

/* Reasons (prefer new table; fall back to legacy) */
$t_reasons = $prefix.'lrofileupload_reasons';
if (!tblExists($db, $t_reasons)) $t_reasons = $prefix.'lrofileupload_rejection_reasons';
if (tblExists($db, $t_reasons)) {
    $counts['reasons'] = (int)$db->getValue("SELECT COUNT(*) FROM `$t_reasons`");
}

/* Logs (prefer audit_log; fall back to action_logs) */
$t_logs = $prefix.'lrofileupload_audit_log';
if (!tblExists($db, $t_logs)) $t_logs = $prefix.'lrofileupload_action_logs';
if (tblExists($db, $t_logs)) {
    $counts['logs'] = (int)$db->getValue("SELECT COUNT(*) FROM `$t_logs`");
    $recent = $db->executeS("SELECT * FROM `$t_logs` ORDER BY id DESC LIMIT 25") ?: [];
}

$adminName = $_SESSION['admin_name'] ?? 'admin';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Master Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{background:#f7f9fc;font-family:system-ui,Segoe UI,Roboto,Helvetica,Arial;margin:0}
    .container{max-width:1100px;margin:24px auto;padding:0 16px}
    .card{background:#fff;border:1px solid #e6e6e6;border-radius:12px;padding:16px;margin-bottom:16px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
    .grid{display:grid;gap:16px}
    @media (min-width: 900px){ .grid.cols-3{grid-template-columns:repeat(3,1fr)} }
    .mono{font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace}
    .table{width:100%;border-collapse:collapse}
    .table th,.table td{border-top:1px solid #eee;padding:8px 10px;font-size:14px}
    .pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;background:#f1f3f5}
    .btn{display:inline-block;padding:6px 10px;border-radius:8px;border:1px solid #cbd5e1;background:#fff;text-decoration:none;color:#111}
    .btn.primary{background:#0d6efd;border-color:#0d6efd;color:#fff}
  </style>
</head>
<body>
<?php if (file_exists(__DIR__.'/nav.php')) include __DIR__.'/nav.php'; ?>

<div class="container">
  <div class="card">
    <h2 style="margin:.2rem 0">Welcome, <?= htmlspecialchars($adminName) ?></h2>
    <div class="mono" style="color:#6b7280">Master dashboard</div>
  </div>

  <div class="grid cols-3">
    <div class="card">
      <div class="mono" style="color:#6b7280">Admins</div>
      <div style="font-size:28px;font-weight:700"><?= $counts['admins'] === null ? '—' : (int)$counts['admins'] ?></div>
      <a class="btn primary" href="manage_admins.php">Manage admins</a>
    </div>
    <div class="card">
      <div class="mono" style="color:#6b7280">Rejection reasons</div>
      <div style="font-size:28px;font-weight:700"><?= $counts['reasons'] === null ? '—' : (int)$counts['reasons'] ?></div>
      <a class="btn primary" href="reasons.php">Edit reasons</a>
    </div>
    <div class="card">
      <div class="mono" style="color:#6b7280">Audit log entries</div>
      <div style="font-size:28px;font-weight:700"><?= $counts['logs'] === null ? '—' : (int)$counts['logs'] ?></div>
      <a class="btn primary" href="logs_unified.php">Open unified logs</a>
    </div>
  </div>

  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center">
      <h3 style="margin:.2rem 0">Recent activity (audit preview)</h3>
      <a class="btn" href="logs_unified.php">View all logs</a>
    </div>
    <?php if ($recent): ?>
      <div style="overflow:auto">
        <table class="table mono">
          <thead>
          <tr><th>ID</th><th>Event</th><th>Admin</th><th>IP</th><th>User-Agent</th><th>Time</th></tr>
          </thead>
          <tbody>
          <?php foreach ($recent as $r): ?>
            <tr>
              <td><?= (int)($r['id'] ?? 0) ?></td>
              <td><?= htmlspecialchars((string)($r['event'] ?? $r['action'] ?? '')) ?></td>
              <td><span class="pill"><?= htmlspecialchars((string)($r['admin_id'] ?? '—')) ?></span></td>
              <td><?= htmlspecialchars((string)($r['ip'] ?? '')) ?></td>
              <td class="mono" style="max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <?= htmlspecialchars((string)($r['ua'] ?? '')) ?>
              </td>
              <td><?= htmlspecialchars((string)($r['created_at'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <div class="mono" style="color:#6b7280">No audit entries to show yet.</div>
    <?php endif; ?>
  </div>

  <div class="grid cols-3">
    <div class="card">
      <div class="mono" style="color:#6b7280">Account</div>
      <a class="btn" href="change_password.php">Change my password</a>
      <a class="btn" href="logout.php">Log out</a>
    </div>
    <div class="card">
      <div class="mono" style="color:#6b7280">Operations</div>
      <a class="btn" href="dashboard.php">Open dashboard</a>
      <a class="btn" href="view_uploads.php">View uploads</a>
    </div>
    <div class="card">
      <div class="mono" style="color:#6b7280">Environment</div>
      <div class="mono">
        PHP <?= htmlspecialchars(PHP_VERSION) ?> |
        Host: <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'cli') ?> |
        Prefix: <?= htmlspecialchars($prefix) ?>
      </div>
    </div>
  </div>
</div>
</body>
</html>
