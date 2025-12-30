<?php
/**************************************************
 * File: /modules/lrofileupload/admin/reasons.php
 **************************************************/
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

/* -------------------- Auth -------------------- */
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

$IS_MASTER = !empty($_SESSION['lro_is_master']) || !empty($_SESSION['is_master']);
if (!$IS_MASTER) {
    if (function_exists('require_cap')) {
        require_cap('can_manage_rejections');
    } else {
        $ok = !empty($_SESSION['can_manage_rejections']) || !empty($_SESSION['lro_can_manage_rejections']);
        if (!$ok) { http_response_code(403); exit('Forbidden (missing can_manage_rejections)'); }
    }
}

if (function_exists('admin_log')) {
    admin_log('view:rejection_reasons', [
        'admin_id' => $_SESSION['admin_id'] ?? null,
        'ip'       => $_SERVER['REMOTE_ADDR'] ?? null,
        'ua'       => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);
}

/* -------------------- PS DB -------------------- */
$prefix = defined('_DB_PREFIX_') ? _DB_PREFIX_ : 'psfc_';
$db     = class_exists('Db') ? Db::getInstance() : null;

if (!$db) {
    http_response_code(500);
    exit('Database adapter not available.');
}

/* -------------------- CSRF -------------------- */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf_token'];

/* -------------------- Helpers -------------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function bad_request(string $msg){ http_response_code(400); header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>$msg]); exit; }

/* ----- Table/column autodetect (safe with Db::getValue LIMIT 1) ----- */
function table_exists_ps(Db $db, string $table): bool {
    $sql = "SELECT COUNT(*) 
              FROM INFORMATION_SCHEMA.TABLES 
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = '".pSQL($table)."'";
    return (int)$db->getValue($sql) > 0;
}

$TABLE_REASONS_NEW = "{$prefix}lrofileupload_reasons";                 // id_reason, reason_text
$TABLE_REASONS_OLD = "{$prefix}lrofileupload_rejection_reasons";       // id_reason, reason
$TABLE_LOGS        = "{$prefix}lrofileupload_rejection_reason_logs";

$TABLE_REASONS = $TABLE_REASONS_NEW;
$COL_ID        = "id_reason";
$COL_TEXT      = "reason_text";

if (!table_exists_ps($db, $TABLE_REASONS_NEW)) {
    if (table_exists_ps($db, $TABLE_REASONS_OLD)) {
        $TABLE_REASONS = $TABLE_REASONS_OLD;
        $COL_TEXT      = "reason";
    } else {
        // neither table exists; page will show a note
        $TABLE_REASONS = '';
    }
}

$hasLogs = table_exists_ps($db, $TABLE_LOGS);

/* -------------------- AJAX actions -------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    if (!$TABLE_REASONS) bad_request('Reasons table not found');

    $tk = (string)($_POST['csrf_token'] ?? '');
    if (!$tk || !hash_equals($CSRF, $tk)) bad_request('Invalid CSRF token');

    $action  = (string)($_POST['action'] ?? '');
    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    if ($adminId <= 0) bad_request('Not authenticated');

    if ($action === 'add') {
        $text = trim((string)($_POST['new_reason'] ?? ''));
        if ($text === '') bad_request('Reason cannot be empty');
        $textSQL = pSQL($text);

        $ok = $db->execute("INSERT INTO `{$TABLE_REASONS}` (`{$COL_TEXT}`) VALUES ('{$textSQL}')");
        if (!$ok) bad_request('Insert failed');

        $id = (int)$db->Insert_ID();

        if ($hasLogs) {
            $db->execute(
                "INSERT INTO `{$TABLE_LOGS}` (admin_id, action, reason_text, id_reason)
                 VALUES ({$adminId}, 'add', '{$textSQL}', {$id})"
            );
        }
        if (function_exists('admin_log')) admin_log('reasons:add', ['admin_id'=>$adminId, 'id_reason'=>$id]);

        echo json_encode(['success'=>true,'id'=>$id,'reason'=>$text]); exit;
    }

    if ($action === 'edit') {
        $id   = (int)($_POST['id_reason'] ?? 0);
        $text = trim((string)($_POST['reason'] ?? ''));
        if ($id <= 0)  bad_request('Invalid ID');
        if ($text === '') bad_request('Reason cannot be empty');
        $textSQL = pSQL($text);

        $ok = $db->execute("UPDATE `{$TABLE_REASONS}` SET `{$COL_TEXT}` = '{$textSQL}' WHERE `{$COL_ID}` = {$id}");
        if (!$ok) bad_request('Update failed');

        if ($hasLogs) {
            $db->execute(
                "INSERT INTO `{$TABLE_LOGS}` (admin_id, action, reason_text, id_reason)
                 VALUES ({$adminId}, 'edit', '{$textSQL}', {$id})"
            );
        }
        if (function_exists('admin_log')) admin_log('reasons:edit', ['admin_id'=>$adminId, 'id_reason'=>$id]);

        echo json_encode(['success'=>true]); exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id_reason'] ?? 0);
        if ($id <= 0) bad_request('Invalid ID');

        if ($hasLogs) {
            $row = $db->getRow("SELECT `{$COL_TEXT}` AS txt FROM `{$TABLE_REASONS}` WHERE `{$COL_ID}` = {$id}");
            $txt = $row ? pSQL((string)$row['txt']) : '';
            $db->execute(
                "INSERT INTO `{$TABLE_LOGS}` (admin_id, action, reason_text, id_reason)
                 VALUES ({$adminId}, 'delete', '{$txt}', {$id})"
            );
        }

        $ok = $db->execute("DELETE FROM `{$TABLE_REASONS}` WHERE `{$COL_ID}` = {$id}");
        if (!$ok) bad_request('Delete failed');

        if (function_exists('admin_log')) admin_log('reasons:delete', ['admin_id'=>$adminId, 'id_reason'=>$id]);

        echo json_encode(['success'=>true]); exit;
    }

    bad_request('Invalid request');
}

/* -------------------- Normal view -------------------- */
$reasons = [];
if ($TABLE_REASONS) {
    $reasons = $db->executeS(
        "SELECT `{$COL_ID}` AS id, `{$COL_TEXT}` AS txt 
           FROM `{$TABLE_REASONS}` 
       ORDER BY `{$COL_ID}` ASC"
    ) ?: [];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Manage Rejection Reasons</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf" content="<?= h($CSRF) ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { background:#f7f9fc; }
    .page-wrap { max-width: 1100px; }
    .card { border-radius:.75rem; box-shadow:0 6px 18px rgba(0,0,0,.06); }
    td[contenteditable]:focus { outline:2px solid #0d6efd; background:#f0f8ff; }
    .page-title { font-weight:600; }
  </style>
</head>
<body>
<div class="container py-4 page-wrap">
  <?php if (file_exists(__DIR__ . '/nav.php')) include __DIR__ . '/nav.php'; ?>

  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="page-title mb-0">Manage Rejection Reasons</h3>
    <?php if ($IS_MASTER): ?>
      <span class="badge text-bg-primary">Master</span>
    <?php else: ?>
      <span class="badge text-bg-secondary">Admin</span>
    <?php endif; ?>
  </div>

  <?php if (!$TABLE_REASONS): ?>
    <div class="alert alert-warning">
      No reasons table was found. Create either
      <code><?= h($TABLE_REASONS_NEW) ?></code> (preferred: <code>id_reason</code>, <code>reason_text</code>)
      or legacy <code><?= h($TABLE_REASONS_OLD) ?></code> (<code>id_reason</code>, <code>reason</code>).
    </div>
  <?php else: ?>
    <div class="card mb-3">
      <div class="card-body">
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-x-octagon"></i></span>
          <input type="text" id="new_reason" class="form-control" placeholder="Enter new rejection reason">
          <button class="btn btn-primary" onclick="addReason()"><i class="bi bi-plus-circle"></i> Add Reason</button>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="table-responsive">
        <table class="table table-bordered table-striped align-middle mb-0" id="reasonsTable">
          <thead class="table-light">
            <tr>
              <th style="width: 90px;">ID</th>
              <th>Reason</th>
              <th style="width: 120px;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($reasons): foreach ($reasons as $r): ?>
              <tr data-id="<?= (int)$r['id'] ?>">
                <td>#<?= (int)$r['id'] ?></td>
                <td contenteditable="true" onblur="editReason(this)"><?= h($r['txt'] ?? '') ?></td>
                <td>
                  <button class="btn btn-sm btn-danger" onclick="deleteReason(<?= (int)$r['id'] ?>)">
                    <i class="bi bi-trash"></i> Delete
                  </button>
                </td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="3" class="text-center text-muted">No rejection reasons added yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

</div>

<script>
const CSRF = document.querySelector('meta[name="csrf"]').getAttribute('content');

function postForm(bodyObj){
  const form = new URLSearchParams(Object.assign({}, bodyObj, { csrf_token: CSRF }));
  return fetch('', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: form
  }).then(r => r.json());
}

function addReason() {
  const input = document.getElementById('new_reason');
  const reason = (input.value || '').trim();
  if (!reason) { alert('Please enter a reason'); return; }

  postForm({ action:'add', new_reason: reason })
    .then(data => {
      if (data && data.success) {
        const tbody = document.querySelector('#reasonsTable tbody');
        const tr = document.createElement('tr');
        tr.setAttribute('data-id', data.id);
        tr.innerHTML = `
          <td>#${data.id}</td>
          <td contenteditable="true" onblur="editReason(this)">${escapeHtml(data.reason || '')}</td>
          <td>
            <button class="btn btn-sm btn-danger" onclick="deleteReason(${data.id})">
              <i class="bi bi-trash"></i> Delete
            </button>
          </td>`;
        tbody.appendChild(tr);
        input.value = '';
      } else {
        alert((data && data.message) ? data.message : 'Add failed');
      }
    })
    .catch(() => alert('Add failed'));
}

function editReason(cell) {
  const row  = cell.closest('tr');
  const id   = row.getAttribute('data-id');
  const text = (cell.innerText || '').trim();

  postForm({ action:'edit', id_reason:id, reason:text })
    .then(data => { if (!data || !data.success) alert('Edit failed'); })
    .catch(() => alert('Edit failed'));
}

function deleteReason(id) {
  if (!confirm('Delete this reason?')) return;

  postForm({ action:'delete', id_reason:id })
    .then(data => {
      if (data && data.success) {
        const tr = document.querySelector('tr[data-id="'+id+'"]');
        if (tr) tr.remove();
      } else {
        alert((data && data.message) ? data.message : 'Delete failed');
      }
    })
    .catch(() => alert('Delete failed'));
}

function escapeHtml(s){
  return String(s)
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'","&#039;");
}
</script>
</body>
</html>
