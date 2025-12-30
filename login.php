<?php
/**************************************************
 * modules/lrofileupload/admin/login.php  (drop-in)
 **************************************************/
declare(strict_types=1);

require_once __DIR__.'/_bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_token'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fail(string $m, string $next='dashboard.php'){
  $_SESSION['_login_err'] = $m;
  header('Location: login.php?next='.rawurlencode($next)); exit;
}

$next = (string)($_GET['next'] ?? $_POST['next'] ?? 'dashboard.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tk   = (string)($_POST['csrf_token'] ?? '');
    $user = trim((string)($_POST['username'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');

    if (!$tk || !hash_equals($CSRF, $tk))                   fail('Bad CSRF token', $next);
    if ($user === '' || $pass === '')                       fail('Username and password required', $next);
    if (!class_exists('Db'))                                fail('Database unavailable', $next);

    $table = _DB_PREFIX_.'lrofileupload_admins';

    // IMPORTANT: no LIMIT here (avoids your SQLSTATE 1064 near LIMIT)
    $sql = 'SELECT * FROM `'.$table.'` WHERE `username` = \''.pSQL($user).'\'';
    $row = Db::getInstance()->getRow($sql);

    // If someone accidentally created the table literally named "PREFIX_lrofileupload_admins"
    if (!$row) {
        $maybeWrong = 'PREFIX_lrofileupload_admins';
        $exists = (bool)Db::getInstance()->getValue('SHOW TABLES LIKE "'.pSQL($maybeWrong).'"');
        if ($exists) {
            $row = Db::getInstance()->getRow(
                'SELECT * FROM `'.$maybeWrong.'` WHERE `username` = \''.pSQL($user).'\''
            );
        }
    }

    if (!$row || empty($row['password_hash']) || !password_verify($pass, (string)$row['password_hash'])) {
        fail('Invalid credentials', $next);
    }

    // Populate session
    $_SESSION['admin_id']   = (int)$row['admin_id'];
    $_SESSION['username']   = (string)$row['username'];
    $_SESSION['admin_name'] = (string)($row['display_name'] ?? $row['username']);
    $_SESSION['is_master']  = (int)($row['is_master'] ?? 0);
    $_SESSION['lro_is_master'] = $_SESSION['is_master'];

    $_SESSION['can_view_dashboard']       = (int)($row['can_view_dashboard'] ?? 0);
    $_SESSION['can_view_uploads']         = (int)($row['can_view_uploads'] ?? 0);
    $_SESSION['can_manage_file_groups']   = (int)($row['can_manage_file_groups'] ?? 0);
    $_SESSION['can_manage_rejections']    = (int)($row['can_manage_rejections'] ?? 0);
    $_SESSION['can_manage_emails']        = (int)($row['can_manage_emails'] ?? 0);
    $_SESSION['can_manage_admins']        = (int)($row['can_manage_admins'] ?? 0);
    $_SESSION['can_view_credential_card'] = (int)($row['can_view_credential_card'] ?? 0);

    // Best-effort last_login
    Db::getInstance()->execute('UPDATE `'.$table.'` SET last_login = NOW() WHERE admin_id='.(int)$row['admin_id']);

    // Optional audit (ignore if file not present)
    if (file_exists(__DIR__.'/auth.php')) {
        require_once __DIR__.'/auth.php';
        if (function_exists('admin_log')) admin_log('auth:login', ['username'=>$user]);
    }

    if (!empty($_POST['to_master']) && $_SESSION['is_master']) {
        header('Location: master_dashboard.php'); exit;
    }
    header('Location: '.$next); exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Module Admin Login</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,Segoe UI,Roboto,Helvetica,Arial;background:#f7f9fc;margin:0}
    .wrap{max-width:420px;margin:8vh auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;box-shadow:0 8px 22px rgba(0,0,0,.06)}
    .title{font-weight:600;margin:0 0 6px}
    .muted{color:#6b7280;font-size:.9rem;margin-bottom:16px}
    .row{margin-bottom:12px}
    label{display:block;margin-bottom:6px}
    input[type=text],input[type=password]{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px}
    .btn{display:inline-block;padding:10px 14px;border:0;border-radius:8px;background:#0d6efd;color:#fff;cursor:pointer}
    .err{background:#fde2e1;border:1px solid #f5a7a2;color:#7a211d;padding:10px;border-radius:8px;margin-bottom:12px}
    .row.flex{display:flex;gap:8px;align-items:center;justify-content:space-between}
  </style>
</head>
<body>
<div class="wrap">
  <h1 class="title">Module Admin Login</h1>
  <div class="muted">Sign in to access the lrofileupload admin tools.</div>

  <?php if (!empty($_SESSION['_login_err'])): ?>
    <div class="err"><?= h($_SESSION['_login_err']); unset($_SESSION['_login_err']); ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
    <input type="hidden" name="next" value="<?= h($next) ?>">

    <div class="row">
      <label>Username</label>
      <input type="text" name="username" autofocus required>
    </div>
    <div class="row">
      <label>Password</label>
      <input type="password" name="password" required>
    </div>

    <div class="row flex">
      <button class="btn" type="submit">Sign in</button>
      <label><input type="checkbox" name="to_master" value="1"> Go to master dashboard</label>
    </div>
  </form>
</div>
</body>
</html>
