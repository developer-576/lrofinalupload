<?php
// modules/lrofileupload/admin/list_uploads.php
define('_PS_MODE_DEV_', false);
define('NO_DEBUG_DISPLAY', true);

require dirname(__DIR__, 3) . '/config/config.inc.php';

$ctx = Context::getContext();
if (!($ctx->employee && method_exists($ctx->employee,'isLoggedBack') && $ctx->employee->isLoggedBack())) {
    http_response_code(403);
    echo 'Back-office login required.';
    exit;
}

$db   = Db::getInstance();
$pref = _DB_PREFIX_;

// Optional filter by customer
$id_customer = (int)Tools::getValue('id_customer');

$where = '1=1';
if ($id_customer > 0) {
    // be tolerant to schema names
    $hasCustomer = (bool)$db->getValue('SHOW COLUMNS FROM `'.$pref.'lrofileupload_uploads` LIKE "id_customer"');
    if ($hasCustomer) $where .= ' AND id_customer='.(int)$id_customer;
}

$rows = $db->executeS('SELECT * FROM `'.$pref.'lrofileupload_uploads` WHERE '.$where.' ORDER BY `id_upload` DESC LIMIT 500') ?: [];
?>
<!doctype html>
<meta charset="utf-8">
<title>LRO Uploads</title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;padding:24px}
table{border-collapse:collapse;width:100%}
th,td{border:1px solid #ddd;padding:8px;font-size:14px}
th{background:#f6f6f6;text-align:left}
a.button{display:inline-block;padding:.35rem .6rem;background:#2e7d32;color:#fff;text-decoration:none;border-radius:4px}
.small{color:#666;font-size:12px}
</style>
<h2>LRO Uploads <?= $id_customer ? 'for customer #'.(int)$id_customer : '' ?></h2>
<table>
<thead>
<tr>
  <th>ID</th>
  <th>Customer</th>
  <th>Group</th>
  <th>Requirement</th>
  <th>Original</th>
  <th>Status</th>
  <th>Uploaded</th>
  <th>Open</th>
</tr>
</thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
  <td><?= (int)$r['id_upload'] ?></td>
  <td><?= isset($r['id_customer']) ? (int)$r['id_customer'] : '-' ?></td>
  <td><?= isset($r['id_group']) ? (int)$r['id_group'] : '-' ?></td>
  <td><?= isset($r['id_requirement']) ? (int)$r['id_requirement'] : '-' ?></td>
  <td><?= htmlspecialchars($r['original_name'] ?? basename($r['file_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
  <td><?= htmlspecialchars($r['status'] ?? ($r['state'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
  <td class="small"><?= htmlspecialchars($r['uploaded_at'] ?? ($r['date_uploaded'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
  <td><a class="button" target="_blank" href="./serve_file.php?id_upload=<?= (int)$r['id_upload'] ?>&inline=1">Open</a></td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?>
<tr><td colspan="8" class="small">No uploads found.</td></tr>
<?php endif; ?>
</tbody>
</table>
