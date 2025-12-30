<?php
declare(strict_types=1);

/**
 * /modules/lrofileupload/admin/email_settings.php
 * Multi-shop safe, CSRF-protected email template settings.
 */

require_once __DIR__ . '/_bootstrap.php';

/* ---------- Auth (admin; masters auto-pass) ---------- */
if (function_exists('lro_require_admin')) {
    lro_require_admin(false);
} elseif (function_exists('require_admin_login')) {
    require_admin_login(false);
} else {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['admin_id'])) { http_response_code(403); exit('Forbidden'); }
}
$IS_MASTER = !empty($_SESSION['lro_is_master']) || !empty($_SESSION['is_master']);

/* ---------- Ensure PS is bootstrapped (fallback) ---------- */
if (!class_exists('Configuration')) {
    $psRoot = realpath(__DIR__ . '/../../../');
    if (!$psRoot || !is_file($psRoot.'/config/config.inc.php')) { die('Cannot locate PrestaShop root'); }
    require_once $psRoot.'/config/config.inc.php';
    require_once $psRoot.'/init.php';
}

/* ---------- Context / shop scope ---------- */
$ctx = class_exists('Context') ? Context::getContext() : null;
$shopId = (int)($ctx && $ctx->shop ? $ctx->shop->id : 0);
$shopGroupId = (int)($ctx && $ctx->shop ? $ctx->shop->id_shop_group : 0);
/* Use NULLs (not 0) for “all shops” in Configuration API */
$SG = $shopGroupId > 0 ? $shopGroupId : null;
$SI = $shopId      > 0 ? $shopId      : null;

/* Helper wrappers that are shop-aware, with global fallback on read */
$confSet = function (string $k, string $v, bool $html = true): bool {
    global $SG, $SI;
    return (bool)Configuration::updateValue($k, $v, $html, $SG, $SI);
};
$confGet = function (string $k) use ($SG, $SI): string {
    $v = Configuration::get($k, null, $SG, $SI);
    if ($v === false || $v === null) { // fallback to global if shop-scoped value absent
        $v = Configuration::get($k);
    }
    return (string)$v;
};

/* ---------- CSRF ---------- */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf_token'];

/* ---------- Fields ---------- */
$TEMPLATE_FIELDS = [
    'LRO_APPROVE_SUBJECT' => 'Approval subject',
    'LRO_APPROVE_BODY'    => 'Approval body',
    'LRO_REJECT_SUBJECT'  => 'Rejection subject',
    'LRO_REJECT_BODY'     => 'Rejection body',
];
$ROUTING_FIELDS = [
    'LRO_MAIL_FROM_NAME'     => 'From name',
    'LRO_MAIL_FROM_EMAIL'    => 'From email',
    'LRO_NOTIFY_APPROVAL_TO' => 'Notify on approval (comma-sep)',
    'LRO_NOTIFY_REJECT_TO'   => 'Notify on rejection (comma-sep)',
];

/* ---------- POST ---------- */
$updated = false;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $tk = (string)($_POST['csrf_token'] ?? '');
    if (!$tk || !hash_equals($CSRF, $tk)) { http_response_code(400); exit('Bad request (CSRF)'); }

    // Save subjects as plain text, bodies as HTML-allowed
    foreach ($TEMPLATE_FIELDS as $key => $_label) {
        $val = (string)($_POST[$key] ?? '');
        $isHtml = str_ends_with($key, '_BODY');
        $confSet($key, $val, $isHtml);
    }
    foreach ($ROUTING_FIELDS as $key => $_label) {
        $val = (string)($_POST[$key] ?? '');
        $confSet($key, $val, false);
    }

    // Stay on the same file; add ?updated=1 without hitting front controllers
    header('Location: email_settings.php?updated=1');
    exit;
}

/* ---------- Load current values ---------- */
$vals = [];
foreach (array_keys($TEMPLATE_FIELDS + $ROUTING_FIELDS) as $key) {
    $vals[$key] = $confGet($key);
}

/* ---------- No cache (avoid stale admin forms) ---------- */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Email Settings</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf" content="<?= h($CSRF) ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#f7f9fc; }
    .page-wrap { max-width: 1100px; }
    .card { border-radius:.75rem; box-shadow:0 6px 18px rgba(0,0,0,.06); }
    .nav-pills .nav-link.active { background:#0d6efd; }
    code.token { background:#eef4ff; padding:.1rem .35rem; border-radius:.35rem; }
  </style>
</head>
<body>
<div class="container py-4 page-wrap">
  <?php if (file_exists(__DIR__ . '/nav.php')) include __DIR__ . '/nav.php'; ?>

  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="mb-0">Email Template Settings</h3>
    <span class="badge text-bg-<?= $IS_MASTER ? 'primary' : 'secondary' ?>">
      <?= $IS_MASTER ? 'Master' : 'Admin' ?> · Shop #<?= (int)$shopId ?: 0 ?>
    </span>
  </div>

  <?php if (!empty($_GET['updated'])): ?>
    <div class="alert alert-success">Email settings updated successfully.</div>
  <?php endif; ?>

  <ul class="nav nav-pills mb-3" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#templates" type="button" role="tab">Templates</button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" data-bs-toggle="pill" data-bs-target="#routing" type="button" role="tab">Routing (optional)</button>
    </li>
  </ul>

  <form method="post" class="card card-body" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">

    <div class="tab-content">
      <!-- Templates -->
      <div class="tab-pane fade show active" id="templates" role="tabpanel">
        <div class="row g-3">
          <div class="col-12 col-md-6">
            <label class="form-label">Approval Subject</label>
            <input type="text" name="LRO_APPROVE_SUBJECT" class="form-control"
                   value="<?= h($vals['LRO_APPROVE_SUBJECT']) ?>">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">Rejection Subject</label>
            <input type="text" name="LRO_REJECT_SUBJECT" class="form-control"
                   value="<?= h($vals['LRO_REJECT_SUBJECT']) ?>">
          </div>

          <div class="col-12">
            <label class="form-label">Approval Body</label>
            <textarea name="LRO_APPROVE_BODY" class="form-control" rows="6"><?= h($vals['LRO_APPROVE_BODY']) ?></textarea>
            <div class="form-text">
              Placeholders: <code class="token">{firstname}</code>, <code class="token">{lastname}</code>,
              <code class="token">{requirement_name}</code>, <code class="token">{group_name}</code>,
              <code class="token">{rejection_reason}</code>, <code class="token">{dashboard_url}</code>,
              <code class="token">{site_name}</code>.
            </div>
          </div>

          <div class="col-12">
            <label class="form-label">Rejection Body</label>
            <textarea name="LRO_REJECT_BODY" class="form-control" rows="6"><?= h($vals['LRO_REJECT_BODY']) ?></textarea>
            <div class="form-text">Tip: include clear instructions and a link for re-upload.</div>
          </div>
        </div>
      </div>

      <!-- Routing -->
      <div class="tab-pane fade" id="routing" role="tabpanel">
        <div class="row g-3">
          <div class="col-12 col-md-6">
            <label class="form-label">From name</label>
            <input type="text" name="LRO_MAIL_FROM_NAME" class="form-control"
                   value="<?= h($vals['LRO_MAIL_FROM_NAME']) ?>">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">From email</label>
            <input type="email" name="LRO_MAIL_FROM_EMAIL" class="form-control"
                   value="<?= h($vals['LRO_MAIL_FROM_EMAIL']) ?>">
          </div>
          <div class="col-12">
            <label class="form-label">Notify on approval</label>
            <input type="text" name="LRO_NOTIFY_APPROVAL_TO" class="form-control"
                   placeholder="e.g. admin@example.com, dev@domain"
                   value="<?= h($vals['LRO_NOTIFY_APPROVAL_TO']) ?>">
            <div class="form-text">Comma-separated addresses to receive approval notifications.</div>
          </div>
          <div class="col-12">
            <label class="form-label">Notify on rejection</label>
            <input type="text" name="LRO_NOTIFY_REJECT_TO" class="form-control"
                   placeholder="e.g. reviewer@example.com"
                   value="<?= h($vals['LRO_NOTIFY_REJECT_TO']) ?>">
          </div>
        </div>
      </div>
    </div>

    <div class="mt-4">
      <button class="btn btn-primary">Save Email Settings</button>
    </div>
  </form>

  <div class="card mt-4">
    <div class="card-header bg-white"><strong>Placeholder Reference</strong></div>
    <div class="card-body small text-muted">
      Use tokens like <code class="token">{firstname}</code>, <code class="token">{lastname}</code>,
      <code class="token">{group_name}</code>, <code class="token">{requirement_name}</code>,
      <code class="token">{rejection_reason}</code>, <code class="token">{dashboard_url}</code>,
      <code class="token">{site_name}</code>.
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
