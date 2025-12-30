<?php
declare(strict_types=1);

/**
 * /modules/lrofileupload/admin/email_settings.php
 */
require_once __DIR__ . '/_bootstrap.php';

/* ---- Auth (admin or master) ---- */
if (function_exists('lro_require_admin')) {
    lro_require_admin(false);
} elseif (function_exists('require_admin_login')) {
    require_admin_login(false);
} else {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['admin_id'])) { http_response_code(403); exit('Forbidden'); }
}
$IS_MASTER = !empty($_SESSION['lro_is_master']) || !empty($_SESSION['is_master']);
if (!$IS_MASTER) {
    $ok = !empty($_SESSION['can_manage_emails']) || !empty($_SESSION['lro_can_manage_emails']);
    if (!$ok && function_exists('require_cap')) require_cap('can_manage_emails');
}

/* ---- Ensure PS classes exist (fallback bootstrap) ---- */
if (!class_exists('Configuration')) {
    $psRoot = realpath(__DIR__ . '/../../../');
    require_once $psRoot.'/config/config.inc.php';
    require_once $psRoot.'/init.php';
}

/* ---- CSRF ---- */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf_token'];

/* ---- Helpers ---- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function redirect_local(string $path): void {
    if (class_exists('Tools') && method_exists('Tools','redirect')) Tools::redirect($path);
    header('Location: '.$path); exit;
}

/* ---- Fields ---- */
$TEMPLATE_FIELDS = [
    'LRO_APPROVE_SUBJECT' => 'Approval subject',
    'LRO_APPROVE_BODY'    => 'Approval body',
    'LRO_REJECT_SUBJECT'  => 'Rejection subject',
    'LRO_REJECT_BODY'     => 'Rejection body',
];
$ROUTING_FIELDS = [
    'LRO_MAIL_FROM_NAME'     => 'From name',
    'LRO_MAIL_FROM_EMAIL'    => 'From email',
    'LRO_NOTIFY_APPROVAL_TO' => 'Notify on approval',
    'LRO_NOTIFY_REJECT_TO'   => 'Notify on rejection',
];

/* ---- Handle POST ---- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $tk = $_POST['csrf_token'] ?? '';
    if (!$tk || !hash_equals($CSRF, $tk)) { http_response_code(400); exit('Bad request (CSRF)'); }

    $db = Db::getInstance();
    $P  = _DB_PREFIX_;

    foreach (array_keys($TEMPLATE_FIELDS + $ROUTING_FIELDS) as $key) {
        $val = (string)($_POST[$key] ?? '');
        $isHtml = (substr($key, -5) === '_BODY'); // body fields allow HTML

        // 1) Ensure a row exists/gets updated
        Configuration::updateValue($key, $val, $isHtml);

        // 2) Make **all** rows with this name hold the same value (kills "stale duplicate" symptom)
        $db->execute(
            'UPDATE `'.$P.'configuration`
             SET value="'.pSQL($val, $isHtml).'", date_upd=NOW()
             WHERE name="'.pSQL($key).'"'
        );
    }

    // 3) Clear PS configuration cache so the page reads the fresh values
    if (method_exists('Configuration','clearConfigurationCache')) {
        Configuration::clearConfigurationCache();
    }

    redirect_local('email_settings.php?updated=1');
}

/* ---- Load (with cache clear + global fallback) ---- */
if (method_exists('Configuration','clearConfigurationCache')) {
  Configuration::clearConfigurationCache();
}
$vals = [];
foreach (array_keys($TEMPLATE_FIELDS + $ROUTING_FIELDS) as $key) {
    $v = Configuration::get($key); // multishop is OFF -> global
    $vals[$key] = (string)$v;
}

/* ---- UI ---- */
$ctx = Context::getContext();
$shopId = (int)$ctx->shop->id;
?><!doctype html>
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
    <span class="badge text-bg-primary">Master · Shop #<?= (int)$shopId ?></span>
  </div>

  <?php if (!empty($_GET['updated'])): ?>
    <div class="alert alert-success">Email settings updated successfully.</div>
  <?php endif; ?>

  <ul class="nav nav-pills mb-3">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#templates" type="button">Templates</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#routing" type="button">Routing (optional)</button></li>
  </ul>

  <form method="post" class="card card-body">
    <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">

    <div class="tab-content">
      <div class="tab-pane fade show active" id="templates">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Approval Subject</label>
            <input type="text" name="LRO_APPROVE_SUBJECT" class="form-control" value="<?= h($vals['LRO_APPROVE_SUBJECT']) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Rejection Subject</label>
            <input type="text" name="LRO_REJECT_SUBJECT" class="form-control" value="<?= h($vals['LRO_REJECT_SUBJECT']) ?>">
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

      <div class="tab-pane fade" id="routing">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">From name</label>
            <input type="text" name="LRO_MAIL_FROM_NAME" class="form-control" value="<?= h($vals['LRO_MAIL_FROM_NAME']) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">From email</label>
            <input type="email" name="LRO_MAIL_FROM_EMAIL" class="form-control" value="<?= h($vals['LRO_MAIL_FROM_EMAIL']) ?>">
          </div>
          <div class="col-12">
            <label class="form-label">Notify on approval</label>
            <input type="text" name="LRO_NOTIFY_APPROVAL_TO" class="form-control" placeholder="e.g. admin@example.com, dev@domain" value="<?= h($vals['LRO_NOTIFY_APPROVAL_TO']) ?>">
            <div class="form-text">Comma-separated addresses to receive approval notifications.</div>
          </div>
          <div class="col-12">
            <label class="form-label">Notify on rejection</label>
            <input type="text" name="LRO_NOTIFY_REJECT_TO" class="form-control" placeholder="e.g. reviewer@example.com" value="<?= h($vals['LRO_NOTIFY_REJECT_TO']) ?>">
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
