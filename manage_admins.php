<?php
/**
 * modules/lrofileupload/admin/manage_admins.php
 * Master-only admin manager with schema repair + PK autodetect.
 */
declare(strict_types=1);

/* ---------- Boot Presta ---------- */
$root = dirname(__FILE__, 4);
require_once $root.'/config/config.inc.php';
require_once $root.'/init.php';
if (is_file(__DIR__.'/_bootstrap.php')) require_once __DIR__.'/_bootstrap.php';

/* ---------- Auth (master required) ---------- */
if (function_exists('lro_require_admin')) {
    lro_require_admin(true);
} elseif (function_exists('require_admin_login')) {
    require_admin_login(true);
} else {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $isMaster = !empty($_SESSION['lro_is_master']) || !empty($_SESSION['is_master']);
    if (empty($_SESSION['admin_id']) || !$isMaster) { http_response_code(403); exit('Forbidden (master only)'); }
}

/* ---------- Utils ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function cur_admin_id(): int { return (int)($_SESSION['admin_id'] ?? $_SESSION['lro_admin_id'] ?? 0); }

/* CSRF */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf_token'];
function require_csrf(): void {
    $tk = (string)(Tools::getValue('csrf_token') ?? '');
    if (!$tk || !hash_equals($_SESSION['csrf_token'], $tk)) { http_response_code(400); exit('Bad CSRF'); }
}

/* ---------- DB / table helpers ---------- */
$db   = Db::getInstance();
$P    = defined('_DB_PREFIX_') ? _DB_PREFIX_ : 'ps_';
$tblA = $P.'lrofileupload_admins';

function table_exists(string $table): bool {
    return (bool)Db::getInstance()->getValue("
        SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA='".pSQL(_DB_NAME_)."'
           AND TABLE_NAME='".pSQL($table)."'");
}
function has_col(string $table, string $col): bool {
    return (bool)Db::getInstance()->getValue("
        SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA='".pSQL(_DB_NAME_)."'
           AND TABLE_NAME='".pSQL($table)."'
           AND COLUMN_NAME='".pSQL($col)."'");
}
function index_exists(string $table, string $idx): bool {
    return (bool)Db::getInstance()->getValue("
        SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA='".pSQL(_DB_NAME_)."'
           AND TABLE_NAME='".pSQL($table)."'
           AND INDEX_NAME='".pSQL($idx)."'");
}
function ensure_unique_idx(string $table, string $idx, array $cols): void {
    if (!index_exists($table, $idx)) {
        Db::getInstance()->execute("ALTER TABLE `{$table}` ADD UNIQUE KEY `{$idx}` (`".implode('`,`',$cols)."`)");
    }
}
/** Detect the PK column name (do NOT add LIMIT; Presta getValue/getRow add it). */
function detect_pk(string $table): string {
    $pk = (string)Db::getInstance()->getValue("
        SELECT COLUMN_NAME
          FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA='".pSQL(_DB_NAME_)."'
           AND TABLE_NAME='".pSQL($table)."'
           AND CONSTRAINT_NAME='PRIMARY'");
    if ($pk !== '') return $pk;
    foreach (['id_admin','admin_id','id'] as $cand) {
        if (has_col($table, $cand)) return $cand;
    }
    return 'id_admin';
}

/* ---------- Ensure table/columns (non-destructive) ---------- */
if (!table_exists($tblA)) {
    $db->execute("
    CREATE TABLE `{$tblA}` (
      `id_admin` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `username` VARCHAR(50) NOT NULL,
      `email`    VARCHAR(255) NOT NULL,
      `password_hash` VARCHAR(255) DEFAULT NULL,
      `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
      `is_master`  TINYINT(1) NOT NULL DEFAULT 0,
      `can_dashboard`       TINYINT(1) NOT NULL DEFAULT 1,
      `can_view_uploads`    TINYINT(1) NOT NULL DEFAULT 1,
      `can_filegroups`      TINYINT(1) NOT NULL DEFAULT 0,
      `can_rejections`      TINYINT(1) NOT NULL DEFAULT 0,
      `can_email_settings`  TINYINT(1) NOT NULL DEFAULT 0,
      `can_manage_admins`   TINYINT(1) NOT NULL DEFAULT 0,
      `can_credential_card` TINYINT(1) NOT NULL DEFAULT 0,
      `date_add` DATETIME DEFAULT CURRENT_TIMESTAMP,
      `date_upd` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id_admin`),
      UNIQUE KEY `uniq_username` (`username`),
      UNIQUE KEY `uniq_email` (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} else {
    $columns = [
      'username'            => "VARCHAR(50) NOT NULL",
      'email'               => "VARCHAR(255) NOT NULL",
      'password_hash'       => "VARCHAR(255) NULL",
      'is_active'           => "TINYINT(1) NOT NULL DEFAULT 1",
      'is_master'           => "TINYINT(1) NOT NULL DEFAULT 0",
      'can_dashboard'       => "TINYINT(1) NOT NULL DEFAULT 1",
      'can_view_uploads'    => "TINYINT(1) NOT NULL DEFAULT 1",
      'can_filegroups'      => "TINYINT(1) NOT NULL DEFAULT 0",
      'can_rejections'      => "TINYINT(1) NOT NULL DEFAULT 0",
      'can_email_settings'  => "TINYINT(1) NOT NULL DEFAULT 0",
      'can_manage_admins'   => "TINYINT(1) NOT NULL DEFAULT 0",
      'can_credential_card' => "TINYINT(1) NOT NULL DEFAULT 0",
      'date_add'            => "DATETIME DEFAULT CURRENT_TIMESTAMP",
      'date_upd'            => "DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];
    foreach ($columns as $c => $ddl) {
        if (!has_col($tblA,$c)) { $db->execute("ALTER TABLE `{$tblA}` ADD COLUMN `{$c}` {$ddl}"); }
    }
    ensure_unique_idx($tblA, 'uniq_username', ['username']);
    ensure_unique_idx($tblA, 'uniq_email',    ['email']);
}

/* Primary-key column */
$PK = detect_pk($tblA);

/* ---------- Roles map ---------- */
$ROLE_COLS = [
  'can_dashboard'       => 'Dashboard',
  'can_view_uploads'    => 'View Uploads',
  'can_filegroups'      => 'File Groups',
  'can_rejections'      => 'Rejections',
  'can_email_settings'  => 'Email Settings',
  'can_manage_admins'   => 'Manage Admins',
  'can_credential_card' => 'Credential Card',
];

/* ---------- Helpers ---------- */
function masters_count(): int {
    global $tblA;
    return (int)Db::getInstance()->getValue("SELECT COUNT(*) FROM `{$tblA}` WHERE `is_master`=1");
}
/* IMPORTANT: no LIMIT here; Db::getRow() adds it automatically. */
function admin_by_pk(int $id): ?array {
    global $tblA, $PK;
    $row = Db::getInstance()->getRow("SELECT *, `{$PK}` AS id_norm FROM `{$tblA}` WHERE `{$PK}`=".(int)$id);
    return $row ?: null;
}
function log_admin_action(string $action, array $meta=[]): void {
    if (function_exists('admin_log')) admin_log('admins:'.$action, $meta);
}

/* ---------- POST actions ---------- */
$flash = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $action = (string)Tools::getValue('action','');

    if ($action === 'create') {
        $username = trim((string)Tools::getValue('username',''));
        $email    = trim((string)Tools::getValue('email',''));
        $pw       = (string)Tools::getValue('password','');
        $is_active = Tools::getValue('is_active') ? 1 : 0;
        $is_master = Tools::getValue('is_master') ? 1 : 0;
        $roles = [];
        foreach ($ROLE_COLS as $col => $_) $roles[$col] = Tools::getValue($col) ? 1 : 0;

        if ($username === '' || $email === '' || strlen($pw) < 8) {
            $flash = 'Please provide username, email and a password (min 8 chars).';
        } else {
            $hash = password_hash($pw, PASSWORD_DEFAULT);
            $ok = $db->execute("
              INSERT INTO `{$tblA}` 
                (username,email,password_hash,is_active,is_master,".implode(',', array_keys($ROLE_COLS)).")
              VALUES
                ('".pSQL($username)."','".pSQL($email)."','".pSQL($hash, true)."',".(int)$is_active.",".(int)$is_master.",
                 ".implode(',', array_map(fn($v)=>(string)((int)$v), array_values($roles))).")
            ");
            if ($ok) { log_admin_action('create', ['username'=>$username,'email'=>$email,'by'=>cur_admin_id()]); Tools::redirectAdmin(basename(__FILE__).'?ok=1'); }
            else     { $flash = 'Create failed (duplicate username or email?)'; }
        }
    }

    if ($action === 'save') {
        $id = (int)(Tools::getValue('id_norm') ?? Tools::getValue('id_admin') ?? Tools::getValue('id') ?? 0);
        $row = $id ? admin_by_pk($id) : null;
        if (!$row) { $flash = 'Admin not found.'; }
        else {
            $me = cur_admin_id();
            $is_active = Tools::getValue('is_active') ? 1 : 0;
            $is_master = Tools::getValue('is_master') ? 1 : 0;

            if ($id === $me && !$is_active) {
                $flash = 'You cannot deactivate your own account.';
            } elseif ((int)$row['is_master'] === 1 && $is_master === 0 && masters_count() <= 1) {
                $flash = 'Refused: this is the last master.';
            } else {
                $sets = ["is_active=".(int)$is_active, "is_master=".(int)$is_master];
                foreach ($ROLE_COLS as $col => $_) $sets[] = "`{$col}`=".(Tools::getValue($col) ? 1 : 0);
                $ok = $db->execute("UPDATE `{$tblA}` SET ".implode(',', $sets)." WHERE `{$PK}`=".(int)$id." LIMIT 1");
                if ($ok) { log_admin_action('save', ['id'=>$id,'by'=>$me]); Tools::redirectAdmin(basename(__FILE__).'?ok=1'); }
                else     { $flash = 'Save failed.'; }
            }
        }
    }

    if ($action === 'reset_pw') {
        $id = (int)(Tools::getValue('id_norm') ?? Tools::getValue('id_admin') ?? Tools::getValue('id') ?? 0);
        $pw = (string)Tools::getValue('new_password','');
        if ($id <= 0 || strlen($pw) < 8) {
            $flash = 'Bad input (password must be 8+ chars).';
        } else {
            $hash = password_hash($pw, PASSWORD_DEFAULT);
            $ok = $db->execute("UPDATE `{$tblA}` SET password_hash='".pSQL($hash, true)."' WHERE `{$PK}`=".(int)$id." LIMIT 1");
            if ($ok) { log_admin_action('reset_pw', ['id'=>$id,'by'=>cur_admin_id()]); Tools::redirectAdmin(basename(__FILE__).'?ok=1'); }
            else     { $flash = 'Update failed.'; }
        }
    }

    if ($action === 'delete') {
        $id = (int)(Tools::getValue('id_norm') ?? Tools::getValue('id_admin') ?? Tools::getValue('id') ?? 0);
        $row = $id ? admin_by_pk($id) : null;
        if (!$row) { $flash = 'Admin not found.'; }
        else {
            $me = cur_admin_id();
            if ($id === $me) {
                $flash = 'You cannot delete yourself.';
            } elseif ((int)$row['is_master'] === 1 && masters_count() <= 1) {
                $flash = 'Refused: this is the last master.';
            } else {
                $ok = $db->execute("DELETE FROM `{$tblA}` WHERE `{$PK}`=".(int)$id." LIMIT 1");
                if ($ok) { log_admin_action('delete', ['id'=>$id,'by'=>$me]); Tools::redirectAdmin(basename(__FILE__).'?ok=1'); }
                else     { $flash = 'Delete failed.'; }
            }
        }
    }
}

/* ---------- Fetch data ---------- */
$admins = $db->executeS("SELECT *, `{$PK}` AS id_norm FROM `{$tblA}` ORDER BY `{$PK}` ASC") ?: [];

/* ---------- View ---------- */
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Manage Admins</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf" content="<?= h($CSRF) ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:#f7f9fc}
    .card{box-shadow:0 6px 18px rgba(0,0,0,.06);border-radius:.75rem}
    .sticky-actions{position:sticky;right:0;background:#fff}
    .cap-col{min-width:130px}
  </style>
</head>
<body class="py-4">
<div class="container">
  <?php if (is_file(__DIR__.'/nav.php')) include __DIR__.'/nav.php'; ?>

  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="mb-0">Manage Admins</h3>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">Add Admin</button>
  </div>

  <?php if (!empty($_GET['ok'])): ?><div class="alert alert-success">Done.</div><?php endif; ?>
  <?php if (!empty($flash)): ?><div class="alert alert-warning"><?= h($flash) ?></div><?php endif; ?>

  <div class="card">
    <div class="card-body p-0">
      <div class="table-responsive">
      <table class="table table-striped table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:70px">ID</th>
            <th style="width:200px">User</th>
            <th style="width:260px">Email</th>
            <th style="width:80px">Active</th>
            <th style="width:80px">Master</th>
            <?php foreach ($ROLE_COLS as $label): ?>
              <th class="cap-col"><?= h($label) ?></th>
            <?php endforeach; ?>
            <th class="sticky-actions" style="width:240px">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($admins as $a): $fid = 'save_'.$a['id_norm']; $did = 'del_'.$a['id_norm']; ?>
          <tr>
            <td>#<?= (int)$a['id_norm'] ?></td>
            <td>
              <div class="fw-semibold"><?= h($a['username']) ?></div>
              <div class="small text-muted"><?= h($a['date_add'] ?? '') ?></div>
            </td>
            <td><?= h($a['email']) ?></td>
            <td><input type="checkbox" name="is_active" value="1" <?= ((int)($a['is_active'] ?? 1)===1?'checked':'') ?> form="<?= h($fid) ?>"></td>
            <td><input type="checkbox" name="is_master" value="1" <?= ((int)($a['is_master'] ?? 0)===1?'checked':'') ?> form="<?= h($fid) ?>"></td>
            <?php foreach ($ROLE_COLS as $col => $label): ?>
              <td><input type="checkbox" name="<?= h($col) ?>" value="1" <?= ((int)($a[$col] ?? 0)===1?'checked':'') ?> form="<?= h($fid) ?>"></td>
            <?php endforeach; ?>
            <td class="sticky-actions">
              <div class="d-flex gap-2 flex-wrap">
                <!-- Save form -->
                <form id="<?= h($fid) ?>" method="post" class="d-inline">
                  <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
                  <input type="hidden" name="action" value="save">
                  <input type="hidden" name="id_norm" value="<?= (int)$a['id_norm'] ?>">
                  <button class="btn btn-sm btn-primary">Save</button>
                </form>

                <!-- Reset PW trigger -->
                <button type="button" class="btn btn-sm btn-outline-secondary"
                        data-bs-toggle="modal"
                        data-bs-target="#pwModal"
                        data-id="<?= (int)$a['id_norm'] ?>"
                        data-username="<?= h($a['username']) ?>">
                        Reset PW
                </button>

                <!-- Delete form -->
                <form id="<?= h($did) ?>" method="post" class="d-inline" onsubmit="return confirm('Delete this admin?');">
                  <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id_norm" value="<?= (int)$a['id_norm'] ?>">
                  <button class="btn btn-sm btn-outline-danger">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; if (!$admins): ?>
          <tr><td colspan="<?= 5 + count($ROLE_COLS) + 1 ?>" class="text-center text-muted">No admins yet.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
      </div>
      <div class="small text-muted p-3">
        Safety rules: you can’t delete yourself, and the last master cannot be demoted or deleted.
      </div>
    </div>
  </div>
</div>

<!-- Add Admin Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form method="post" class="modal-content" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
      <input type="hidden" name="action" value="create">
      <div class="modal-header"><h5 class="modal-title">Add Admin</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Username</label>
            <input type="text" name="username" class="form-control" required>
          </div>
          <div class="col-md-5">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control" minlength="8" required>
            <div class="form-text">Min 8 characters.</div>
          </div>
          <div class="col-12">
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" name="is_active" value="1" checked id="add_active">
              <label class="form-check-label" for="add_active">Active</label>
            </div>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" name="is_master" value="1" id="add_master">
              <label class="form-check-label" for="add_master">Master</label>
            </div>
          </div>
          <div class="col-12">
            <label class="form-label">Roles</label>
            <div class="row">
              <?php foreach ($ROLE_COLS as $col => $label): ?>
                <div class="col-md-4">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="<?= h($col) ?>" value="1" id="add_<?= h($col) ?>"
                      <?= in_array($col, ['can_dashboard','can_view_uploads'], true) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="add_<?= h($col) ?>"><?= h($label) ?></label>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary">Create Admin</button>
      </div>
    </form>
  </div>
</div>

<!-- Reset PW Modal -->
<div class="modal fade" id="pwModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
      <input type="hidden" name="action" value="reset_pw">
      <input type="hidden" name="id_norm" id="pw_id" value="">
      <div class="modal-header"><h5 class="modal-title">Reset Password</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-2 small text-muted" id="pw_user_help"></div>
        <label class="form-label">New Password</label>
        <input type="password" class="form-control" name="new_password" minlength="8" required>
        <div class="form-text">Minimum 8 characters.</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary">Update Password</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const pwModal = document.getElementById('pwModal');
pwModal?.addEventListener('show.bs.modal', ev => {
  const btn = ev.relatedTarget;
  const id  = btn?.getAttribute('data-id');
  const un  = btn?.getAttribute('data-username') || '';
  document.getElementById('pw_id').value = id || '';
  document.getElementById('pw_user_help').textContent = un ? ('User: '+un) : '';
});
</script>
</body>
</html>
