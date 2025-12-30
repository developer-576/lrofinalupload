<?php
// modules/lrofileupload/admin/view_uploaded_files.php
declare(strict_types=1);

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Strongly discourage caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once dirname(__FILE__, 4) . '/config/config.inc.php';
require_once dirname(__FILE__, 4) . '/init.php';

require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/auth.php';
require_admin_login();

$prefix = _DB_PREFIX_;
$db     = Db::getInstance();

// CSRF token (fallback if not set in session_bootstrap)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf_token'] ?? '';

/* ---------- Fetch uploads, then group by customer ---------- */
$sql = "
SELECT 
  u.*,
  c.firstname, c.lastname,
  r.requirement_name,
  g.group_name,
  CONCAT('customer_', u.id_customer, '/group_', u.id_group, '/', u.file_name) AS rel_path
FROM {$prefix}lrofileupload_uploads u
LEFT JOIN {$prefix}customer c ON u.id_customer = c.id_customer
LEFT JOIN {$prefix}lrofileupload_group_requirements r ON u.id_requirement = r.id_requirement
LEFT JOIN {$prefix}lrofileupload_product_groups g ON u.id_group = g.id_group
ORDER BY c.firstname, c.lastname, u.uploaded_at DESC
";
$rows = $db->executeS($sql) ?: [];

/* Group rows by customer */
$byCustomer = [];
foreach ($rows as $r) {
    $cid    = (int)($r['id_customer'] ?? 0);
    $given  = trim((string)($r['firstname'] ?? ''));
    $family = trim((string)($r['lastname'] ?? ''));
    $key    = $cid.'|'.$given.'|'.$family;
    if (!isset($byCustomer[$key])) $byCustomer[$key] = [];
    $byCustomer[$key][] = $r;
}

/* Fallback reasons (server-rendered). JS will refresh from reasons_feed.php */
$reasons = $db->executeS("
    SELECT id_reason, reason_text
    FROM {$prefix}lrofileupload_reasons
    ORDER BY id_reason DESC
");
if ($reasons === false || $reasons === null) {
    // In case your schema used a different table name previously:
    $reasons = $db->executeS("
        SELECT id_reason, reason_text
        FROM {$prefix}lrofileupload_rejection_reasons
        ORDER BY id_reason DESC
    ") ?: [];
}

/* Base URLs */
$shopBase = Tools::getShopDomainSsl(true, true); // e.g., https://example.com
$viewer   = $shopBase . '/modules/lrofileupload/admin/pdfjs/web/viewer.html';

/* Helpers */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function status_badge($status){
    $s = strtolower((string)$status);
    $class = 'secondary';
    if ($s === 'approved') $class = 'success';
    elseif ($s === 'rejected') $class = 'danger';
    elseif ($s === 'pending') $class = 'warning';
    return '<span class="badge bg-'.$class.'">'.ucfirst($s ?: 'pending').'</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Uploaded Files</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="csrf" content="<?= h($CSRF) ?>" />

  <!-- Bootstrap & jQuery -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <style>
    body { background:#f7f9fc; }
    .page-title { font-weight:600; }
    .table-sm > :not(caption) > * > * { padding-top:.35rem; padding-bottom:.35rem; }
    .table td, .table th { vertical-align: middle; font-size:.92rem; }
    td.actions { white-space: nowrap; }
    td.actions .btn { padding:.25rem .5rem; font-size:.80rem; line-height:1.1; margin-right:.25rem; }
    .nowrap { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    th.col-given { min-width:160px; } th.col-group { min-width:130px; } th.col-req { min-width:220px; } th.col-file { min-width:360px; }
    td.col-given { max-width:200px; } td.col-group { max-width:160px; } td.col-req { max-width:280px; } td.col-file { max-width:420px; }
    thead.table-light th { position:sticky; top:0; z-index:2; }
    .cust-block { background:#fff; border:1px solid #e5e7eb; border-radius:.5rem; }
    .cust-header { background:#fff; border-bottom:1px solid #e5e7eb; padding:.6rem .9rem; display:flex; align-items:center; justify-content:space-between; cursor:pointer; border-radius:.5rem .5rem 0 0; }
    .cust-header .meta { font-size:.9rem; color:#6b7280; }
    .caret { transition:transform .2s ease; }
    .collapsed .caret { transform:rotate(-90deg); }
  </style>
</head>
<body>
<div class="container-fluid py-4">
  <?php if (file_exists(__DIR__ . '/nav.php')) { include __DIR__ . '/nav.php'; } ?>

  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="page-title mb-0">Uploaded Files</h3>
  </div>

  <?php if (!$rows): ?>
    <div class="alert alert-light border">No uploads found.</div>
  <?php else: ?>
    <div class="accordion" id="custAccordion">
      <?php $i=0; foreach ($byCustomer as $key => $list):
        [$cid, $given, $family] = explode('|', $key, 3);
        $cid   = (int)$cid;
        $given = $given ?: 'Unknown';
        $count = count($list);
        $accId = 'cust_'.$cid.'_'.$i++;
      ?>
      <div class="mb-3 cust-block">
        <button class="cust-header w-100 text-start collapsed" type="button"
                data-bs-toggle="collapse" data-bs-target="#<?= h($accId) ?>"
                aria-expanded="false" aria-controls="<?= h($accId) ?>">
          <div>
            <strong><?= h($given) ?></strong>
            <?php if ($family): ?><span class="text-muted"> (<?= h($family) ?>)</span><?php endif; ?>
          </div>
          <div class="d-flex align-items-center gap-3">
            <span class="meta"><?= $count ?> file<?= $count===1?'':'s' ?></span>
            <span class="caret">▸</span>
          </div>
        </button>

        <div id="<?= h($accId) ?>" class="collapse" data-bs-parent="#custAccordion">
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th class="col-given">Given names</th>
                  <th class="col-group">Group</th>
                  <th class="col-req">Requirement</th>
                  <th class="col-file">File</th>
                  <th>Status</th>
                  <th>Uploaded At</th>
                  <th style="min-width:240px;">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($list as $u):
                $id         = $u['id_upload'] ?? null;
                $gid        = (int)($u['id_group'] ?? 0);
                $fname      = $u['file_name'] ?? '';
                $groupName  = $u['group_name'] ?? '';
                $reqName    = $u['requirement_name'] ?? '';
                $status     = $u['status'] ?? '';
                $uploadedAt = $u['uploaded_at'] ?? '';

                $rel       = $u['rel_path'] ?? '';
                $serveUrl  = $shopBase . '/modules/lrofileupload/admin/serve_file.php?file=' . rawurlencode($rel);
                $viewerUrl = $viewer   . '?file=' . rawurlencode($serveUrl);
              ?>
                <tr>
                  <td class="col-given nowrap" title="<?= h($given) ?>"><?= h($given) ?></td>
                  <td class="col-group nowrap" title="<?= h($groupName) ?>"><?= $groupName ? h($groupName) : '<em class="text-muted">—</em>' ?></td>
                  <td class="col-req nowrap" title="<?= h($reqName) ?>"><?= $reqName ? h($reqName) : '<em class="text-muted">—</em>' ?></td>
                  <td class="col-file nowrap" title="<?= h($fname) ?>">
                    <?php if ($fname && $rel): ?>
                      <a href="<?= h($viewerUrl) ?>" target="_blank" rel="noopener"><?= h($fname) ?></a>
                    <?php else: ?>
                      <em class="text-muted">No filename</em>
                    <?php endif; ?>
                  </td>
                  <td><?= status_badge($status) ?></td>
                  <td class="nowrap" title="<?= h($uploadedAt) ?>"><?= h($uploadedAt) ?></td>
                  <td class="actions">
                    <?php if ($fname && $rel): ?>
                      <a class="btn btn-outline-primary btn-sm" href="<?= h($viewerUrl) ?>" target="_blank" rel="noopener">Preview</a>
                    <?php endif; ?>
                    <?php if ($id): ?>
                      <button class="btn btn-success btn-sm" onclick="approveFile(<?= (int)$id ?>)">Approve</button>
                      <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#rejectModal"
                              data-upload-id="<?= (int)$id ?>">Reject</button>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" onsubmit="submitReject(event)">
      <div class="modal-header">
        <h5 class="modal-title">Reject File</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="reject_id" value="">
        <div class="mb-3">
          <label for="reject_reason" class="form-label">Reason</label>
          <select class="form-select" id="reject_reason" required>
            <option value="" selected disabled>Choose a reason...</option>
            <?php foreach ($reasons as $r): ?>
              <option value="<?= (int)$r['id_reason'] ?>"><?= h($r['reason_text']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!$reasons): ?>
            <div class="form-text text-warning">
              No reasons found. Add reasons in “Rejection Reasons”.
            </div>
          <?php endif; ?>
        </div>
        <div class="mb-3">
          <label for="reject_notes" class="form-label">Notes (optional)</label>
          <textarea class="form-control" id="reject_notes" rows="3" placeholder="Optional message to the customer"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-danger">Confirm Reject</button>
      </div>
    </form>
  </div>
</div>

<script>
// CSRF for AJAX
const CSRF = document.querySelector('meta[name="csrf"]').getAttribute('content') || '';
$.ajaxSetup({ headers: { 'X-CSRF-Token': CSRF }, cache: false });

// Approve
function approveFile(id) {
  $.ajax({
    url: 'ajax_approve.php',
    type: 'POST',
    dataType: 'json',
    data: { id: id, csrf: CSRF }
  }).done(function(resp){
    if (resp && resp.success) {
      location.reload();
    } else {
      alert('❌ ' + ((resp && (resp.error||resp.message)) ? (resp.error||resp.message) : 'Approval failed'));
      if (typeof resp !== 'object') window.location = 'login.php';
    }
  }).fail(function(xhr){
    const msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : 'Server error';
    alert('❌ ' + msg);
  });
}

// When opening Reject modal: set id + fetch latest reasons fresh (no cache)
document.getElementById('rejectModal').addEventListener('show.bs.modal', function (event) {
  const btn = event.relatedTarget;
  const id  = btn ? btn.getAttribute('data-upload-id') : '';
  document.getElementById('reject_id').value = id || '';
  document.getElementById('reject_reason').value = '';
  document.getElementById('reject_notes').value = '';

  // Pull fresh reasons from reasons_feed.php every time
  $.ajax({
    url: 'reasons_feed.php',
    method: 'GET',
    dataType: 'json',
    cache: false,
    data: { ts: Date.now() }, // cache buster
    headers: { 'Cache-Control': 'no-cache', 'Pragma': 'no-cache' }
  }).done(function(res){
    const $sel = $('#reject_reason');
    const current = $sel.val() || '';
    $sel.empty().append(new Option('Choose a reason...', ''));
    if (res && res.success && Array.isArray(res.reasons)) {
      res.reasons.forEach(function(r){
        $sel.append(new Option(r.reason_text, r.id));
      });
    } else {
      // Fallback to server-rendered list if API fails
      <?php if ($reasons): ?>
      const fallback = <?= json_encode($reasons, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
      fallback.forEach(function(r){ $sel.append(new Option(r.reason_text, r.id_reason)); });
      <?php endif; ?>
    }
    if (current) $sel.val(current);
  }).fail(function(){
    // silent fail; user can still pick from fallback options
  });
});

// Submit Reject
function submitReject(e){
  e.preventDefault();
  const id = $('#reject_id').val();
  const reason_id = $('#reject_reason').val();
  const notes = $('#reject_notes').val();

  if (!id || !reason_id) { alert('Please select a reason.'); return; }

  $.ajax({
    url: 'ajax_reject.php',
    type: 'POST',
    dataType: 'json',
    data: { id: id, reason_id: reason_id, notes: notes, csrf: CSRF }
  }).done(function(resp){
    if (resp && resp.success) {
      location.reload();
    } else {
      alert('❌ ' + ((resp && (resp.error||resp.message)) ? (resp.error||resp.message) : 'Rejection failed'));
      if (typeof resp !== 'object') window.location = 'login.php';
    }
  }).fail(function(xhr){
    const msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : 'Server error';
    alert('❌ ' + msg);
  });
}
</script>
</body>
</html>
