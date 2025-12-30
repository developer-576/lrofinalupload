<?php
/**************************************************
 * File: manage_admins_api.php
 * Path: /modules/lrofileupload/admin/manage_admins_api.php
 * JSON API for manage_admins.php
 **************************************************/
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// ---- Bootstrap PrestaShop ----
$psRoot = realpath(__DIR__ . '/../../../');
if (!$psRoot || !file_exists($psRoot . '/config/config.inc.php')) {
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>'Cannot locate PrestaShop root']); exit;
}
require_once $psRoot . '/config/config.inc.php';
require_once $psRoot . '/init.php';

// ---- Session & Auth ----
require_once __DIR__ . '/session_bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/auth.php';
require_admin_login();
require_cap('can_manage_admins');

// ---- CSRF ----
$csrf = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$csrf)) {
  http_response_code(403);
  echo json_encode(['success'=>false,'message'=>'Invalid CSRF token']);
  exit;
}

// ---- Helpers ----
header('Content-Type: application/json; charset=utf-8');

$prefix = _DB_PREFIX_;
$dsn = 'mysql:host=' . _DB_SERVER_ . ';dbname=' . _DB_NAME_ . ';charset=utf8mb4';
$pdo = new PDO($dsn, _DB_USER_, _DB_PASSWD_, [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

function json_ok(array $extra = []){ echo json_encode(['success'=>true]+$extra); exit; }
function json_err(string $msg, array $extra = []){ echo json_encode(['success'=>false,'message'=>$msg]+$extra); exit; }

function roleBool($arr, string $key): int {
  return !empty($arr[$key]) ? 1 : 0;
}

function shop_base_url(): string {
  $domain = Tools::getShopDomainSsl(true, true);
  $base   = __PS_BASE_URI__;
  return rtrim($domain, '/') . rtrim($base, '/');
}

function login_url(): string {
  return shop_base_url() . '/modules/lrofileupload/admin/login.php';
}

function send_admin_email(string $toEmail, string $toName, string $username, string $plainPassword, string &$errorOut = ''): bool {
  $shopName = Configuration::get('PS_SHOP_NAME') ?: 'Your Shop';
  $from     = Configuration::get('PS_SHOP_EMAIL') ?: 'no-reply@'.parse_url(shop_base_url(), PHP_URL_HOST);
  $subject  = "Your admin access for {$shopName}";
  $login    = login_url();

  $html = <<<HTML
  <div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#222">
    <p>Hi {$toName},</p>
    <p>An admin account has been created for you on <strong>{$shopName}</strong>.</p>
    <p><strong>Login:</strong> <a href="{$login}">{$login}</a></p>
    <p><strong>Username:</strong> {$username}<br>
       <strong>Password:</strong> {$plainPassword}</p>
    <p>For security, please log in and change your password right away.</p>
    <p>Kind regards,<br>{$shopName} Team</p>
  </div>
HTML;

  $text = "Hi {$toName},\n\n".
          "An admin account has been created for you on {$shopName}.\n\n".
          "Login: {$login}\n".
          "Username: {$username}\n".
          "Password: {$plainPassword}\n\n".
          "Please change your password after logging in.\n\n".
          "Kind regards,\n{$shopName} Team\n";

  try {
    $headers  = "From: {$shopName} <{$from}>\r\n";
    $headers .= "Reply-To: {$shopName} <{$from}>\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $boundary = md5(uniqid((string)mt_rand(), true));
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
    $body .= $text . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $body .= $html . "\r\n";
    $body .= "--{$boundary}--\r\n";

    $ok = @mail($toEmail, '=?UTF-8?B?'.base64_encode($subject).'?=', $body, $headers);
    if (!$ok) {
      $errorOut = 'PHP mail() returned false.';
    }
    return $ok;
  } catch (Throwable $e) {
    $errorOut = 'Mailer exception: '.$e->getMessage();
    return false;
  }
}

function log_audit(PDO $pdo, string $action, array $extra = []): void {
  global $prefix;
  try {
    $table = "{$prefix}lrofileupload_logs";
    $pdo->query("SELECT 1 FROM {$table} LIMIT 1");
    $sql = "INSERT INTO {$table} (ts, actor_admin_id, actor_name, action, target_admin_id, target_name, ip, before_json, after_json)
            VALUES (NOW(), :actor_id, :actor_name, :action, :target_id, :target_name, :ip, :before_json, :after_json)";
    $stmt = $pdo->prepare($sql);
    $actorId = (int)($_SESSION['admin_id'] ?? 0);
    $actorNm = (string)($_SESSION['admin_name'] ?? '');
    $stmt->execute([
      ':actor_id'   => $actorId,
      ':actor_name' => $actorNm,
      ':action'     => $action,
      ':target_id'  => (int)($extra['target_admin_id'] ?? 0),
      ':target_name'=> (string)($extra['target_name'] ?? ''),
      ':ip'         => Tools::getRemoteAddr(),
      ':before_json'=> json_encode($extra['before'] ?? [], JSON_UNESCAPED_UNICODE),
      ':after_json' => json_encode($extra['after'] ?? [], JSON_UNESCAPED_UNICODE),
    ]);
  } catch (Throwable $e) { /* ignore */ }
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
  case 'create_admin': {
    $in = $_POST;
    $username = trim((string)($in['username'] ?? ''));
    $email    = trim((string)($in['email'] ?? ''));
    $password = (string)($in['password'] ?? '');

    if ($username === '' || $email === '' || $password === '') {
      json_err('Username, email, and password are required.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      json_err('Invalid email address.');
    }
    if (strlen($password) < 8) {
      json_err('Password must be at least 8 characters.');
    }

    $roles = [
      'is_master'               => roleBool($in, 'role_master'),
      'can_view_dashboard'      => roleBool($in, 'role_dashboard'),
      'can_view_uploads'        => roleBool($in, 'role_uploads'),
      'can_manage_file_groups'  => roleBool($in, 'role_file_groups'),
      'can_manage_rejections'   => roleBool($in, 'role_rejections'),
      'can_manage_emails'       => roleBool($in, 'role_emails'),
      'can_view_credential_card'=> roleBool($in, 'role_credential_card'),
      'can_manage_admins'       => roleBool($in, 'role_admins'),
    ];

    $stmt = $pdo->prepare("SELECT 1 FROM {$prefix}lrofileupload_admins WHERE username = :u OR email = :e LIMIT 1");
    $stmt->execute([':u'=>$username, ':e'=>$email]);
    if ($stmt->fetch()) {
      json_err('Username or email already exists.');
    }

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost'=>10]);

    $sql = "INSERT INTO {$prefix}lrofileupload_admins
            (username,email,password_hash,is_master,can_view_dashboard,can_view_uploads,can_manage_file_groups,can_manage_rejections,can_manage_emails,can_manage_admins,can_view_credential_card,added_on)
            VALUES
            (:u,:e,:p,:m,:d,:v,:fg,:rj,:em,:ad,:cc,NOW())";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      ':u'=>$username, ':e'=>$email, ':p'=>$hash,
      ':m'=>$roles['is_master'],
      ':d'=>$roles['can_view_dashboard'],
      ':v'=>$roles['can_view_uploads'],
      ':fg'=>$roles['can_manage_file_groups'],
      ':rj'=>$roles['can_manage_rejections'],
      ':em'=>$roles['can_manage_emails'],
      ':ad'=>$roles['can_manage_admins'],
      ':cc'=>$roles['can_view_credential_card'],
    ]);

    $newId = (int)$pdo->lastInsertId();

    $emailErr = '';
    $sent = send_admin_email($email, $username, $username, $password, $emailErr);

    log_audit($pdo, 'create_admin', [
      'target_admin_id' => $newId,
      'target_name'     => $username,
      'after'           => ['username'=>$username, 'email'=>$email, 'roles'=>$roles, 'email_sent'=>$sent, 'email_error'=>$emailErr],
    ]);

    if ($sent) json_ok(['message'=>'Admin created and email sent.','email_sent'=>true, 'id'=>$newId]);
    else json_ok(['message'=>'Admin created but email failed: '.$emailErr,'email_sent'=>false, 'id'=>$newId]);
  }

  case 'update_roles': {
    $adminId = (int)($_POST['admin_id'] ?? 0);
    if ($adminId <= 0) json_err('Invalid admin_id');

    $cur = $pdo->prepare("SELECT * FROM {$prefix}lrofileupload_admins WHERE admin_id = :id LIMIT 1");
    $cur->execute([':id'=>$adminId]);
    $before = $cur->fetch() ?: [];

    $isCurrentMaster = !empty($_SESSION['is_master']);
    $in = $_POST;

    $roles = [
      'is_master'               => $isCurrentMaster ? roleBool($in, 'is_master') : (int)$before['is_master'],
      'can_view_dashboard'      => roleBool($in, 'can_view_dashboard'),
      'can_view_uploads'        => roleBool($in, 'can_view_uploads'),
      'can_manage_file_groups'  => roleBool($in, 'can_manage_file_groups'),
      'can_manage_rejections'   => roleBool($in, 'can_manage_rejections'),
      'can_manage_emails'       => roleBool($in, 'can_manage_emails'),
      'can_manage_admins'       => $isCurrentMaster ? roleBool($in, 'can_manage_admins') : (int)$before['can_manage_admins'],
      'can_view_credential_card'=> roleBool($in, 'can_view_credential_card'),
    ];

    $sql = "UPDATE {$prefix}lrofileupload_admins SET
            is_master=:m, can_view_dashboard=:d, can_view_uploads=:v,
            can_manage_file_groups=:fg, can_manage_rejections=:rj,
            can_manage_emails=:em, can_manage_admins=:ad, can_view_credential_card=:cc
            WHERE admin_id=:id LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      ':m'=>$roles['is_master'], ':d'=>$roles['can_view_dashboard'], ':v'=>$roles['can_view_uploads'],
      ':fg'=>$roles['can_manage_file_groups'], ':rj'=>$roles['can_manage_rejections'],
      ':em'=>$roles['can_manage_emails'], ':ad'=>$roles['can_manage_admins'], ':cc'=>$roles['can_view_credential_card'],
      ':id'=>$adminId
    ]);

    log_audit($pdo, 'update_roles', [
      'target_admin_id'=>$adminId,
      'target_name'=>$before['username'] ?? '',
      'before'=>$before,
      'after'=>$roles,
    ]);

    json_ok(['message'=>'Roles updated']);
  }

  case 'reset_password': {
    $adminId = (int)($_POST['admin_id'] ?? 0);
    $new     = (string)($_POST['new_password'] ?? '');
    if ($adminId <= 0 || $new === '') json_err('admin_id and new_password required');
    if (strlen($new) < 8) json_err('Password must be at least 8 characters');

    $stmt = $pdo->prepare("SELECT username,email FROM {$prefix}lrofileupload_admins WHERE admin_id=:id LIMIT 1");
    $stmt->execute([':id'=>$adminId]);
    $row = $stmt->fetch();
    if (!$row) json_err('Admin not found');

    $hash = password_hash($new, PASSWORD_BCRYPT, ['cost'=>10]);
    $upd = $pdo->prepare("UPDATE {$prefix}lrofileupload_admins SET password_hash=:p WHERE admin_id=:id LIMIT 1");
    $upd->execute([':p'=>$hash, ':id'=>$adminId]);

    $emailErr = '';
    $sent = send_admin_email($row['email'] ?? '', $row['username'] ?? '', $row['username'] ?? '', $new, $emailErr);

    log_audit($pdo, 'reset_password', [
      'target_admin_id'=>$adminId,
      'target_name'=>$row['username'] ?? '',
      'after'=>['email_sent'=>$sent, 'email_error'=>$emailErr],
    ]);

    if ($sent) json_ok(['message'=>'Password reset and email sent','email_sent'=>true]);
    else json_ok(['message'=>'Password reset; email failed: '.$emailErr,'email_sent'=>false]);
  }

  case 'delete_admin': {
    $adminId = (int)($_POST['admin_id'] ?? 0);
    if ($adminId <= 0) json_err('Invalid admin_id');

    if ((int)($_SESSION['admin_id'] ?? 0) === $adminId) {
      json_err('You cannot delete yourself.');
    }

    $stmt = $pdo->prepare("SELECT username FROM {$prefix}lrofileupload_admins WHERE admin_id=:id LIMIT 1");
    $stmt->execute([':id'=>$adminId]);
    $row = $stmt->fetch();

    $del = $pdo->prepare("DELETE FROM {$prefix}lrofileupload_admins WHERE admin_id=:id LIMIT 1");
    $del->execute([':id'=>$adminId]);

    log_audit($pdo, 'delete_admin', [
      'target_admin_id'=>$adminId,
      'target_name'=>$row['username'] ?? '',
    ]);

    json_ok(['message'=>'Admin deleted']);
  }

  case 'list_audit': {
    $limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
    try {
      $rows = $pdo->query("SELECT ts,actor_admin_id,actor_name,action,target_admin_id,target_name,ip,before_json,after_json
                           FROM {$prefix}lrofileupload_logs
                           ORDER BY ts DESC
                           LIMIT {$limit}")->fetchAll();
      json_ok(['rows'=>$rows]);
    } catch (Throwable $e) {
      json_ok(['rows'=>[]]);
    }
  }

  default:
    json_err('Unknown action: '.$action);
}
