<?php
// Hardening + session
ini_set('session.cookie_httponly','1');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on') ini_set('session.cookie_secure','1');
if (function_exists('opcache_reset') && isset($_GET['reset'])) { @opcache_reset(); }
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// Find PrestaShop settings.inc.php (ONLY dependency)
function find_ps_settings() {
    $candidates = [];
    $rootFromHere = dirname(__DIR__, 3); // .../public_html
    $candidates[] = $rootFromHere.'/config/settings.inc.php';
    if (!empty($_SERVER['DOCUMENT_ROOT'])) $candidates[] = rtrim($_SERVER['DOCUMENT_ROOT'],'/').'/config/settings.inc.php';
    $candidates[] = dirname(__DIR__, 4).'/config/settings.inc.php';
    $seen = [];
    foreach ($candidates as $p) {
        if (!$p || isset($seen[$p])) continue;
        $seen[$p] = true;
        if (is_file($p)) return $p;
    }
    return null;
}
$settings = find_ps_settings();
if (!$settings) { http_response_code(500); die('Cannot locate PrestaShop config/settings.inc.php'); }
require_once $settings;
if (!defined('_DB_SERVER_') || !defined('_DB_NAME_') || !defined('_DB_USER_')) { http_response_code(500); die('DB constants missing in settings.inc.php'); }
$prefix = defined('_DB_PREFIX_') ? _DB_PREFIX_ : 'psfc_';

// Connect PDO
try {
    $pdo = new PDO(
        'mysql:host='._DB_SERVER_.';dbname='._DB_NAME_.';charset=utf8mb4',
        _DB_USER_, defined('_DB_PASSWD_') ? _DB_PASSWD_ : '',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
    );
} catch (Throwable $e) {
    http_response_code(500);
    die('Database connection failed.');
}

// Ensure table + seed
$tbl = $prefix.'lrofileupload_admins';
$pdo->exec("
CREATE TABLE IF NOT EXISTS `$tbl` (
  `admin_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(100) NOT NULL,
  `email` VARCHAR(190) DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `is_master` TINYINT(1) NOT NULL DEFAULT 0,
  `can_view_dashboard` TINYINT(1) NOT NULL DEFAULT 1,
  `can_view_uploads` TINYINT(1) NOT NULL DEFAULT 0,
  `can_manage_file_groups` TINYINT(1) NOT NULL DEFAULT 0,
  `can_manage_rejections` TINYINT(1) NOT NULL DEFAULT 0,
  `can_manage_emails` TINYINT(1) NOT NULL DEFAULT 0,
  `can_manage_admins` TINYINT(1) NOT NULL DEFAULT 0,
  `can_view_credential_card` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`admin_id`),
  UNIQUE KEY `uniq_username` (`username`),
  UNIQUE KEY `uniq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
if ((int)$pdo->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn() === 0) {
    $hash = password_hash('ChangeMe123!', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO `$tbl` (username,email,password_hash,is_master,can_manage_admins) VALUES (?,?,?,?,?)")
        ->execute(['admin','admin@example.com',$hash,1,1]);
    $_SESSION['seed_notice'] = 'Seeded master: admin / ChangeMe123!  (change after login)';
}

// Handle login
$error = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $u = trim($_POST['username'] ?? '');
    $p = (string)($_POST['password'] ?? '');
    if ($u !== '' && $p !== '') {
        $st = $pdo->prepare("SELECT * FROM `$tbl` WHERE username=:u OR email=:u LIMIT 1");
        $st->execute([':u'=>$u]);
        $a = $st->fetch();
        if ($a && password_verify($p, $a['password_hash'])) {
            $_SESSION['admin_id']   = (int)$a['admin_id'];
            $_SESSION['admin_name'] = $a['username'];
            $_SESSION['is_master']  = (int)$a['is_master'];
            $_SESSION['caps'] = [
                'can_view_dashboard'       => (int)($a['can_view_dashboard'] ?? 1),
                'can_view_uploads'         => (int)($a['can_view_uploads'] ?? 0),
                'can_manage_file_groups'   => (int)($a['can_manage_file_groups'] ?? 0),
                'can_manage_rejections'    => (int)($a['can_manage_rejections'] ?? 0),
                'can_manage_emails'        => (int)($a['can_manage_emails'] ?? 0),
                'can_manage_admins'        => (int)($a['can_manage_admins'] ?? 0),
                'can_view_credential_card' => (int)($a['can_view_credential_card'] ?? 0),
            ];
            header('Location: dashboard.php'); exit;
        }
    }
    $error = 'Invalid username/email or password.';
}
?>
<!doctype html><html><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>LRO Admin Login</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>body{background:#f6f7fb;min-height:100vh;display:flex;align-items:center;justify-content:center}
.box{width:100%;max-width:440px;background:#fff;padding:28px;border-radius:14px;box-shadow:0 8px 30px rgba(0,0,0,.08)}
.brand{font-weight:700;font-size:22px;color:#0d6efd}</style>
</head><body>
<div class="box">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div class="brand">LRO File Uploads</div><span class="text-muted small">Admin</span>
  </div>
  <?php if (!empty($_SESSION['seed_notice'])): ?><div class="alert alert-info"><?=$_SESSION['seed_notice']; unset($_SESSION['seed_notice']);?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?=htmlspecialchars($error)?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <div class="mb-3"><label class="form-label">Email or username</label>
      <input class="form-control" name="username" required autofocus autocomplete="username"></div>
    <div class="mb-3"><label class="form-label">Password</label>
      <input class="form-control" name="password" type="password" required autocomplete="current-password"></div>
    <button class="btn btn-primary w-100">Sign in</button>
  </form>
</div>
</body></html>
