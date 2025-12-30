<?php
/**************************************************
 * File: /modules/lrofileupload/admin/configure_emails.php
 * Purpose: Manage approval/rejection email templates (DB table version)
 **************************************************/
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

/* ---------- PrestaShop bootstrap (config + init) ---------- */
(function () {
    $dir = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        if (file_exists($dir . '/config/config.inc.php')) {
            require_once $dir . '/config/config.inc.php';
            require_once $dir . '/init.php';
            return;
        }
        $dir = dirname($dir);
    }
    // Fallback for common layout: /modules/lrofileupload/admin/ -> root is 3 up
    $root = dirname(__DIR__, 2);
    if (file_exists($root . '/config/config.inc.php')) {
        require_once $root . '/config/config.inc.php';
        require_once $root . '/init.php';
        return;
    }
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(500);
    exit('Could not locate PrestaShop root (config/config.inc.php).');
})();

/* ---------- Session & Auth ---------- */
require_once __DIR__ . '/session_bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/auth.php';
require_admin_login();                // must be logged in
if (function_exists('require_cap')) {
    require_cap('can_manage_emails'); // capability check (masters pass)
}

/* ---------- PDO helper (use PS constants) ---------- */
function pdo_conn(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $pdo = new PDO(
        'mysql:host=' . _DB_SERVER_ . ';dbname=' . _DB_NAME_ . ';charset=utf8mb4',
        _DB_USER_,
        _DB_PASSWD_,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    return $pdo;
}
$pdo    = pdo_conn();
$prefix = defined('_DB_PREFIX_') ? _DB_PREFIX_ : 'ps_';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------- CSRF ---------- */
if (function_exists('csrf_token')) {
    $CSRF = csrf_token();
} else {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $CSRF = $_SESSION['csrf_token'];
}

/* ---------- Ensure table exists ---------- */
$pdo->exec("
CREATE TABLE IF NOT EXISTS `{$prefix}lrofileupload_email_templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `template_type` VARCHAR(32) NOT NULL,
  `subject` TEXT NOT NULL,
  `body` MEDIUMTEXT NOT NULL,
  `last_updated` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_template_type` (`template_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

/* ---------- POST handling ---------- */
$message = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // CSRF check (accept 'csrf_token' or 'csrf')
    $tk = $_POST['csrf_token'] ?? ($_POST['csrf'] ?? '');
    $valid = hash_equals((string)$CSRF, (string)$tk);
    if (!$valid) {
        http_response_code(400);
        exit('Bad request (CSRF).');
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            INSERT INTO `{$prefix}lrofileupload_email_templates` (template_type, subject, body, last_updated)
            VALUES (:t, :s, :b, NOW())
            ON DUPLICATE KEY UPDATE subject = VALUES(subject), body = VALUES(body), last_updated = NOW()
        ");

        $types = ['approval','rejection'];
        foreach ($types as $type) {
            $subject = (string)($_POST["subject_{$type}"] ?? '');
            $body    = (string)($_POST["body_{$type}"] ?? '');
            $stmt->execute([':t'=>$type, ':s'=>$subject, ':b'=>$body]);
        }

        $pdo->commit();
        // Rotate CSRF after sensitive write (optional)
        if (function_exists('csrf_token')) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        } else {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        $CSRF = $_SESSION['csrf'] ?? $_SESSION['csrf_token'] ?? $CSRF;

        $message = 'Email templates updated successfully.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
            exit('Error saving templates: ' . $e->getMessage());
        }
        exit('Error saving templates.');
    }
}

/* ---------- Fetch current templates ---------- */
$templates = ['approval'=>['subject'=>'','body'=>''], 'rejection'=>['subject'=>'','body'=>'']];
try {
    $stmt = $pdo->query("SELECT template_type, subject, body FROM `{$prefix}lrofileupload_email_templates`");
    foreach ($stmt->fetchAll() as $row) {
        $key = (string)$row['template_type'];
        if (isset($templates[$key])) {
            $templates[$key] = ['subject'=>$row['subject'], 'body'=>$row['body']];
        }
    }
} catch (Throwable $e) {
    // Table might be new; keep defaults. Show hint in dev.
    if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
        $message = 'Note: table was just created or unreadable: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Configure Email Templates</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf" content="<?= h($CSRF) ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#f7f9fc; }
    .page-wrap { max-width: 900px; margin: 24px auto; }
    .card { border-radius:.75rem; box-shadow: 0 4px 16px rgba(0,0,0,.06); }
    .form-label { font-weight: 600; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; }
  </style>
</head>
<body class="py-4">

<div class="page-wrap">
  <?php if (file_exists(__DIR__ . '/nav.php')) include __DIR__ . '/nav.php'; ?>

  <h2 class="mb-3">Email Template Settings</h2>

  <?php if ($message): ?>
    <div class="alert alert-info"><?= h($message) ?></div>
  <?php endif; ?>

  <form method="post" class="card card-body">
    <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">

    <?php foreach (['approval'=>'Approval', 'rejection'=>'Rejection'] as $key => $label): ?>
      <div class="mb-4">
        <h5 class="mb-3"><?= h($label) ?> Email</h5>
        <div class="mb-3">
          <label class="form-label" for="subject_<?= h($key) ?>">Subject</label>
          <input type="text" id="subject_<?= h($key) ?>" name="subject_<?= h($key) ?>" class="form-control"
                 value="<?= h($templates[$key]['subject'] ?? '') ?>">
        </div>
        <div class="mb-2">
          <label class="form-label" for="body_<?= h($key) ?>">Email Body</label>
          <textarea id="body_<?= h($key) ?>" name="body_<?= h($key) ?>" rows="8" class="form-control"><?= h($templates[$key]['body'] ?? '') ?></textarea>
        </div>
        <div class="form-text">
          Available placeholders:
          <code class="mono">{customer_name}</code>,
          <code class="mono">{file_name}</code><?= ($key === 'rejection' ? ', <code class="mono">{reason}</code>' : '') ?>.
        </div>
      </div>
      <?php if ($key === 'approval'): ?><hr class="my-4"><?php endif; ?>
    <?php endforeach; ?>

    <div class="d-flex gap-2">
      <button class="btn btn-primary">Save Templates</button>
      <a href="master_dashboard.php" class="btn btn-outline-secondary">Back to Dashboard</a>
    </div>
  </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
