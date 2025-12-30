<?php
require_once __DIR__ . '/session_bootstrap.php';

/**************************************************
 * modules/lrofileupload/admin/logs.php
 * Master-only action log viewer + CSV export
 **************************************************/
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// ---- PrestaShop bootstrap ----
$psRoot = realpath(__DIR__ . '/../../../');
if (!$psRoot || !file_exists($psRoot . '/config/config.inc.php')) {
    die('Cannot locate PrestaShop root from ' . __DIR__);
}
require_once $psRoot . '/config/config.inc.php';
require_once $psRoot . '/init.php';

// ---- Auth ----
require_once __DIR__ . '/auth.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_admin_login(); // must be logged in

// Master-only gate
if (empty($_SESSION['is_master'])) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><div style="font-family:sans-serif;padding:2rem">'
       . '<h3>Access denied</h3><p>This page is for master admins only.</p></div>';
    exit;
}

$prefix = _DB_PREFIX_;
$db     = Db::getInstance();

/* --- (Optional) ensure table exists in very minimal form --- */
try {
    $table = $prefix . 'lrofileupload_action_logs';
    $exists = $db->executeS("
        SELECT TABLE_NAME FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = '" . pSQL(_DB_NAME_) . "'
          AND TABLE_NAME   = '" . pSQL($table) . "'
    ");
    if (!$exists) {
        $db->execute("
            CREATE TABLE `{$table}` (
              `id_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `admin_id` INT NULL,
              `group_id` INT NULL,
              `action_type` VARCHAR(64) NOT NULL,
              `target_type` VARCHAR(64) DEFAULT NULL,
              `target_id` INT DEFAULT NULL,
              `description` TEXT,
              PRIMARY KEY (`id_log`),
              KEY `created_at_idx` (`created_at`),
              KEY `admin_id_idx` (`admin_id`),
              KEY `group_id_idx` (`group_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }
} catch (Throwable $e) {
    // ignore; we'll still try to render gracefully
}

/* --- Filters --- */
$group_id_filter = (int)Tools::getValue('group_id', 0);
$admin_id_filter = (int)Tools::getValue('admin_id', 0);

/* --- Lookup data for filter dropdowns --- */
$groups = $db->executeS("SELECT id_group, group_name FROM {$prefix}lrofileupload_product_groups ORDER BY group_name") ?: [];
// FIX: use correct PK admin_id (not id)
$admins = $db->executeS("SELECT admin_id, username FROM {$prefix}lrofileupload_admins ORDER BY username") ?: [];

/* --- Build WHERE clause safely --- */
$where = '1';
if ($group_id_filter > 0) { $where .= ' AND l.group_id = ' . (int)$group_id_filter; }
if ($admin_id_filter > 0) { $where .= ' AND l.admin_id = ' . (int)$admin_id_filter; }

/* --- Fetch logs --- */
// FIX: JOIN on a.admin_id (not a.id)
$logs = $db->executeS("
    SELECT l.*, a.username, g.group_name
    FROM {$prefix}lrofileupload_action_logs l
    LEFT JOIN {$prefix}lrofileupload_admins a ON l.admin_id = a.admin_id
    LEFT JOIN {$prefix}lrofileupload_product_groups g ON l.group_id = g.id_group
    WHERE {$where}
    ORDER BY l.created_at DESC, l.id_log DESC
    LIMIT 500
") ?: [];

/* --- CSV export --- */
if (Tools::getValue('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=action_logs.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Timestamp','Admin','Group','Action','Target','Description']);
    foreach ($logs as $log) {
        fputcsv($out, [
            (string)$log['created_at'],
            (string)($log['username'] ?? ''),
            (string)($log['group_name'] ?? ''),
            (string)$log['action_type'],
            trim((string)($log['target_type'] ?? '') . ' #' . (string)($log['target_id'] ?? '')),
            (string)($log['description'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Action Logs</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#f7f9fc; }
    .page-title { font-weight:600; }
  </style>
</head>
<body>
<div class="container py-4">
  <?php if (file_exists(__DIR__ . '/nav.php')) { include __DIR__ . '/nav.php'; } ?>

  <h3 class="page-title mb-3">Action Logs</h3>

  <form method="get" class="row g-3 mb-4">
    <div class="col-md-4">
      <label for="group_id" class="form-label">Filter by Group</label>
      <select id="group_id" name="group_id" class="form-select">
        <option value="0">All Groups</option>
        <?php foreach ($groups as $g): ?>
          <option value="<?= (int)$g['id_group'] ?>" <?= $group_id_filter === (int)$g['id_group'] ? 'selected' : '' ?>>
            <?= h($g['group_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label for="admin_id" class="form-label">Filter by Admin</label>
      <select id="admin_id" name="admin_id" class="form-select">
        <option value="0">All Admins</option>
        <?php foreach ($admins as $a): ?>
          <option value="<?= (int)$a['admin_id'] ?>" <?= $admin_id_filter === (int)$a['admin_id'] ? 'selected' : '' ?>>
            <?= h($a['username']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4 d-flex align-items-end gap-2">
      <button type="submit" class="btn btn-primary">Filter</button>
      <?php
        $qs = http_build_query([
          'group_id' => $group_id_filter,
          'admin_id' => $admin_id_filter,
          'export'   => 'csv'
        ]);
      ?>
      <a href="?<?= h($qs) ?>" class="btn btn-success">Export CSV</a>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-bordered table-striped table-hover align-middle">
      <thead class="table-dark">
        <tr>
          <th style="white-space:nowrap;">Date</th>
          <th>Admin</th>
          <th>Group</th>
          <th>Action</th>
          <th>Target</th>
          <th>Description</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$logs): ?>
          <tr><td colspan="6" class="text-center text-muted">No logs found.</td></tr>
        <?php else: foreach ($logs as $log): ?>
          <tr>
            <td><?= h($log['created_at']) ?></td>
            <td><?= h($log['username'] ?? '-') ?></td>
            <td><?= h($log['group_name'] ?? '-') ?></td>
            <td><?= h($log['action_type']) ?></td>
            <td><?= h(trim((string)($log['target_type'] ?? '') . ' #' . (string)($log['target_id'] ?? ''))) ?></td>
            <td><?= nl2br(h($log['description'] ?? '')) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
