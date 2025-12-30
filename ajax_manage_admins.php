<?php
/**
 * modules/lrofileupload/admin/ajax_manage_admins.php
 * Robust, drop-in Admin management AJAX for LRO File Upload.
 *
 * Handles:
 *  - create_admin / create
 *  - update_admin / update / save_roles
 *  - reset_password / reset_pw
 *  - delete_admin / delete
 *
 * Requirements:
 *  - Uses PrestaShop Db + module session/auth.
 *  - CSRF protected (accepts csrf_token or csrf).
 *  - Auto-creates/repairs lrofileupload_admins table & columns if missing.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

/* ---------------- Bullet-proof PS bootstrap ---------------- */
(function () {
    $dir = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        if (is_file($dir.'/config/config.inc.php') && is_file($dir.'/init.php')) {
            require_once $dir.'/config/config.inc.php';
            require_once $dir.'/init.php';
            return;
        }
        $dir = dirname($dir);
    }
    // Hard fallback from /modules/lrofileupload/admin/*
    $root = dirname(__DIR__, 3);
    if (is_file($root.'/config/config.inc.php') && is_file($root.'/init.php')) {
        require_once $root.'/config/config.inc.php';
        require_once $root.'/init.php';
        return;
    }
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Cannot locate PrestaShop root']);
    exit;
})();

/* ---------------- Module session & auth ---------------- */
require_once __DIR__.'/session_bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__.'/auth.php';
if (function_exists('require_master_login')) {
    // Only masters can change admins
    require_master_login();
} else {
    // Fallback: allow only if session says master
    if (empty($_SESSION['lro_is_master']) && empty($_SESSION['is_master'])) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'Forbidden']);
        exit;
    }
}
if (function_exists('require_cap')) {
    // If you use capability gates, this one is the relevant one
    require_cap('can_manage_admins');
}

/* ---------------- Helpers ---------------- */
function ok($v){ return !empty($v) || $v===0 || $v==='0'; }
function jfail(string $msg, int $code=200){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }
function jok(array $extra=[]){ echo json_encode(['ok'=>true]+$extra); exit; }
function bval($v): int {
    if (is_bool($v)) return $v ? 1 : 0;
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1','true','yes','on','y'], true) ? 1 : 0;
}
function ensure_col(string $table, string $def): void {
    $db = Db::getInstance();
    // $def like "`is_active` TINYINT(1) NOT NULL DEFAULT 1"
    if (!preg_match('~`([^`]+)`~', $def, $m)) return;
    $col = $m[1];
    $exists = (bool)$db->getValue("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA='".pSQL(_DB_NAME_)."' AND TABLE_NAME='".pSQL($table)."' AND COLUMN_NAME='".pSQL($col)."'");
    if (!$exists) $db->execute("ALTER TABLE `{$table}` ADD {$def}");
}
function col_exists(string $table, string $col): bool {
    return (bool)Db::getInstance()->getValue("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA='".pSQL(_DB_NAME_)."' AND TABLE_NAME='".pSQL($table)."' AND COLUMN_NAME='".pSQL($col)."'");
}

/* ---------------- CSRF ---------------- */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$CSRF = (string)($_POST['csrf_token'] ?? $_POST['csrf'] ?? '');
if (!$CSRF || !hash_equals($_SESSION['csrf_token'], $CSRF)) {
    jfail('Bad CSRF', 400);
}

/* ---------------- DB & table ---------------- */
$P   = _DB_PREFIX_;
$tbl = $P.'lrofileupload_admins';
$db  = Db::getInstance();

/* Create table if missing (idempotent) */
$db->execute("
CREATE TABLE IF NOT EXISTS `{$tbl}` (
  `id_admin` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(120) NOT NULL,
  `email`    VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_master` TINYINT(1) NOT NULL DEFAULT 0,
  `can_dashboard` TINYINT(1) NOT NULL DEFAULT 1,
  `can_view_uploads` TINYINT(1) NOT NULL DEFAULT 1,
  `can_manage_file_groups` TINYINT(1) NOT NULL DEFAULT 0,
  `can_manage_rejections` TINYINT(1) NOT NULL DEFAULT 0,
  `can_manage_admins` TINYINT(1) NOT NULL DEFAULT 0,
  `can_email_settings` TINYINT(1) NOT NULL DEFAULT 0,
  `can_credential_card` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NULL,
  PRIMARY KEY (`id_admin`),
  UNIQUE KEY `uniq_username` (`username`),
  UNIQUE KEY `uniq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* Backfill missing columns if the table already existed with an older schema */
ensure_col($tbl, "`password_hash` VARCHAR(255) NULL");
ensure_col($tbl, "`is_active` TINYINT(1) NOT NULL DEFAULT 1");
ensure_col($tbl, "`is_master` TINYINT(1) NOT NULL DEFAULT 0");
foreach ([
    'can_dashboard','can_view_uploads','can_manage_file_groups','can_manage_rejections',
    'can_manage_admins','can_email_settings','can_credential_card'
] as $c) {
    ensure_col($tbl, "`{$c}` TINYINT(1) NOT NULL DEFAULT 0");
}
ensure_col($tbl, "`created_at` DATETIME NULL");

/* Password column fallback (older installs) */
$PASS_COL = col_exists($tbl,'password_hash') ? 'password_hash'
           : (col_exists($tbl,'passhash') ? 'passhash'
           : (col_exists($tbl,'password') ? 'password' : 'password_hash'));

/* Utility: count active masters */
$masters_count = (int)$db->getValue("SELECT COUNT(*) FROM `{$tbl}` WHERE is_master=1 AND is_active=1");

/* ---------------- Action routing ---------------- */
$action = (string)($_POST['action'] ?? '');
$action = strtolower($action);

/* Normalize variants used by old UIs */
$map = [
    'create' => 'create_admin',
    'add'    => 'create_admin',
    'save'   => 'update_admin',
    'update' => 'update_admin',
    'save_roles' => 'update_admin',
    'reset_pw' => 'reset_password',
    'reset'    => 'reset_password',
    'delete'   => 'delete_admin',
];
if (isset($map[$action])) $action = $map[$action];

if ($action === 'create_admin') {
    $username = trim((string)($_POST['username'] ?? ''));
    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($username === '' || $email === '' || strlen($password) < 8) {
        jfail('Bad input (username/email/password).');
    }

    // Roles & flags
    $is_active = bval($_POST['is_active'] ?? 1);
    $is_master = bval($_POST['is_master'] ?? 0);

    $roles = [
        'can_dashboard'           => bval($_POST['can_dashboard']           ?? 1),
        'can_view_uploads'        => bval($_POST['can_view_uploads']        ?? 1),
        'can_manage_file_groups'  => bval($_POST['can_manage_file_groups']  ?? 0),
        'can_manage_rejections'   => bval($_POST['can_manage_rejections']   ?? 0),
        'can_manage_admins'       => bval($_POST['can_manage_admins']       ?? 0),
        'can_email_settings'      => bval($_POST['can_email_settings']      ?? 0),
        'can_credential_card'     => bval($_POST['can_credential_card']     ?? 0),
    ];

    // Uniqueness check
    $existsU = (int)$db->getValue("SELECT COUNT(*) FROM `{$tbl}` WHERE username='".pSQL($username)."'");
    $existsE = (int)$db->getValue("SELECT COUNT(*) FROM `{$tbl}` WHERE email='".pSQL($email)."'");
    if ($existsU > 0) jfail('Username already exists.');
    if ($existsE > 0) jfail('Email already exists.');

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $cols = [
        'username'   => pSQL($username),
        'email'      => pSQL($email),
        $PASS_COL    => pSQL($hash),
        'is_active'  => (int)$is_active,
        'is_master'  => (int)$is_master,
        'created_at' => date('Y-m-d H:i:s'),
    ] + $roles;

    $ok = $db->insert(basename($tbl), $cols);
    if (!$ok) jfail('Create failed');

    jok();
}

if ($action === 'update_admin') {
    $id = (int)($_POST['id_admin'] ?? $_POST['id'] ?? 0);
    if ($id <= 0) jfail('Bad admin id.');

    $is_active = bval($_POST['is_active'] ?? 1);
    $is_master = bval($_POST['is_master'] ?? 0);

    $roles = [
        'can_dashboard'           => bval($_POST['can_dashboard']           ?? 1),
        'can_view_uploads'        => bval($_POST['can_view_uploads']        ?? 1),
        'can_manage_file_groups'  => bval($_POST['can_manage_file_groups']  ?? 0),
        'can_manage_rejections'   => bval($_POST['can_manage_rejections']   ?? 0),
        'can_manage_admins'       => bval($_POST['can_manage_admins']       ?? 0),
        'can_email_settings'      => bval($_POST['can_email_settings']      ?? 0),
        'can_credential_card'     => bval($_POST['can_credential_card']     ?? 0),
    ];

    // Protect last master: cannot demote or deactivate the only active master
    $row = $db->getRow("SELECT id_admin, is_master, is_active FROM `{$tbl}` WHERE id_admin={$id} LIMIT 1");
    if (!$row) jfail('Admin not found.');

    if ((int)$row['is_master'] === 1 && ($is_master === 0 || $is_active === 0)) {
        $others = (int)$db->getValue("SELECT COUNT(*) FROM `{$tbl}` WHERE id_admin<>".$id." AND is_master=1 AND is_active=1");
        if ($others === 0) jfail('Safety: cannot demote/deactivate the last master.');
    }

    $cols = ['is_active'=>$is_active, 'is_master'=>$is_master] + $roles;
    $ok = $db->update(basename($tbl), $cols, 'id_admin='.(int)$id, 1);
    if (!$ok) jfail('Save failed');

    jok();
}

if ($action === 'reset_password') {
    $id = (int)($_POST['id_admin'] ?? $_POST['id'] ?? 0);
    $new = (string)($_POST['password'] ?? $_POST['new_password'] ?? '');
    if ($id <= 0 || strlen($new) < 8) jfail('Bad input.');

    $hash = password_hash($new, PASSWORD_DEFAULT);
    $ok = $db->update(basename($tbl), [$PASS_COL => pSQL($hash)], 'id_admin='.(int)$id, 1);
    if (!$ok) jfail('Update failed');

    jok();
}

if ($action === 'delete_admin') {
    $id = (int)($_POST['id_admin'] ?? $_POST['id'] ?? 0);
    if ($id <= 0) jfail('Bad admin id.');

    $row = $db->getRow("SELECT is_master, is_active FROM `{$tbl}` WHERE id_admin={$id} LIMIT 1");
    if (!$row) jfail('Admin not found.');
    if ((int)$row['is_master'] === 1 && (int)$row['is_active'] === 1) {
        $others = (int)$db->getValue("SELECT COUNT(*) FROM `{$tbl}` WHERE id_admin<>".$id." AND is_master=1 AND is_active=1");
        if ($others === 0) jfail('Safety: cannot delete the last active master.');
    }

    $ok = $db->delete(basename($tbl), 'id_admin='.(int)$id, 1);
    if (!$ok) jfail('Delete failed');

    jok();
}

/* Unknown action */
jfail('Unknown action.');
