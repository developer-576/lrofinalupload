<?php
/**
 * File: modules/lrofileupload/admin/admin_login.php
 * Secure module admin login (PrestaShop context + no duplicate LIMIT).
 */
declare(strict_types=1);

/* ---------- Bootstrap (session + PrestaShop) ---------- */
$bootstrap = __DIR__ . '/session_bootstrap.php';
if (is_file($bootstrap)) {
    require_once $bootstrap; // starts session with safe flags
} else {
    // minimal session if helper is missing
    if (session_status() !== PHP_SESSION_ACTIVE) {
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_strict_mode', '1');
        session_start();
    }
}
$psRoot = dirname(__FILE__, 4);
require_once $psRoot . '/config/config.inc.php';
require_once $psRoot . '/init.php';

/* ---------- Tiny helpers ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function admin_base_url(): string {
    return Tools::getShopDomainSsl(true) . __PS_BASE_URI__ . 'modules/lrofileupload/admin/';
}

/** Check table exists (NO explicit LIMIT here). */
function tableExists(string $table): bool {
    return (bool) Db::getInstance()->getValue(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA='" . pSQL(_DB_NAME_) . "'
           AND TABLE_NAME='" . pSQL($table) . "'"
    );
}

/** Check column exists (NO explicit LIMIT here). */
function colExists(string $table, string $col): bool {
    return (bool) Db::getInstance()->getValue(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA='" . pSQL(_DB_NAME_) . "'
           AND TABLE_NAME='" . pSQL($table) . "'
           AND COLUMN_NAME='" . pSQL($col) . "'"
    );
}

/* ---------- CSRF token ---------- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf_token'];

/* ---------- If already logged in ---------- */
if (!empty($_SESSION['lro_admin_id'])) {
    $to = !empty($_SESSION['lro_is_master']) ? 'master_dashboard.php' : 'dashboard.php';
    Tools::redirect(admin_base_url() . $to);
    exit;
}

/* ---------- Table/column autodetect ---------- */
$tbl = _DB_PREFIX_ . 'lrofileupload_admins';

if (!tableExists($tbl)) {
    http_response_code(500);
    die('Admin table not found: ' . h($tbl));
}

$COL_ID     = colExists($tbl,'admin_id')        ? 'admin_id'      : (colExists($tbl,'id_admin') ? 'id_admin' : 'admin_id');
$COL_USER   = colExists($tbl,'username')        ? 'username'      : (colExists($tbl,'user_name') ? 'user_name' : 'username');
$COL_EMAIL  = colExists($tbl,'email')           ? 'email'         : null;
$COL_HASH   = colExists($tbl,'password_hash')   ? 'password_hash' : (colExists($tbl,'password') ? 'password' : 'password_hash');
$COL_ACTIVE = colExists($tbl,'is_active')       ? 'is_active'     : (colExists($tbl,'active') ? 'active' : null);
$COL_MASTER = colExists($tbl,'is_master')       ? 'is_master'     : (colExists($tbl,'is_super') ? 'is_super' : null);

/* ---------- Handle POST ---------- */
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    $token = (string)Tools::getValue('csrf_token', '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(400); exit('Invalid CSRF token');
    }

    $u  = trim((string)Tools::getValue('username', ''));
    $pw = (string)Tools::getValue('password', '');

    if ($u === '' || $pw === '') {
        $flash = 'Please provide both username and password.';
    } else {
        // WHERE (by email if valid and column exists, otherwise by username)
        if ($COL_EMAIL && Validate::isEmail($u)) {
            $where = "`{$COL_EMAIL}`='" . pSQL($u) . "'";
        } else {
            $where = "`{$COL_USER}`='" . pSQL($u) . "'";
        }

        // Do NOT append LIMIT here — Db::getRow() adds it internally
        $sql   = "SELECT * FROM `{$tbl}` WHERE {$where}";
        $admin = Db::getInstance()->getRow($sql);

        if (!$admin) {
            $flash = 'Invalid username or password.';
        } elseif ($COL_ACTIVE && (int)$admin[$COL_ACTIVE] === 0) {
            $flash = 'Account disabled.';
        } else {
            // Verify password (supports legacy md5 in a pinch)
            $ok = false;
            if ($COL_HASH === 'password_hash') {
                $ok = password_verify($pw, (string)$admin[$COL_HASH]);
            } else {
                $ok = hash_equals((string)$admin[$COL_HASH], md5($pw));
            }

            if (!$ok) {
                $flash = 'Invalid username or password.';
            } else {
                // Success — establish session
                $_SESSION['lro_admin_id']   = (int)$admin[$COL_ID];
                $_SESSION['lro_username']   = (string)$admin[$COL_USER];
                $_SESSION['lro_is_master']  = $COL_MASTER ? ((int)$admin[$COL_MASTER] === 1) : false;

                $_SESSION['lro_admin_caps'] = [
                    'master'         => (bool)$_SESSION['lro_is_master'],
                    'manage_admins'  => (bool)$_SESSION['lro_is_master'],
                ];

                // New CSRF for this session
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                $to = $_SESSION['lro_is_master'] ? 'master_dashboard.php' : 'dashboard.php';
                Tools::redirect(admin_base_url() . $to);
                exit;
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Module Admin Login</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style> body{background:#f7f9fc}.login-card{max-width:520px;margin:7vh auto} </style>
</head>
<body>
  <div class="card shadow-sm login-card">
    <div class="card-body">
      <h5 class="card-title mb-3">File Uploads – Admin</h5>

      <?php if (!empty($flash)): ?>
        <div class="alert alert-warning py-2 mb-3"><?= h($flash) ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
        <div class="mb-3">
          <label class="form-label" for="username">Username</label>
          <input class="form-control" id="username" name="username" type="text" autofocus>
        </div>
        <div class="mb-3">
          <label class="form-label" for="password">Password</label>
          <input class="form-control" id="password" name="password" type="password">
        </div>
        <button class="btn btn-primary w-100">Sign in</button>
      </form>

      <div class="text-muted small mt-3">v1 — fixed (no duplicate LIMIT)</div>
    </div>
  </div>
</body>
</html>
