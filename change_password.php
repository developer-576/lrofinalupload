<?php
declare(strict_types=1);
$psRoot = dirname(__FILE__, 4);
require_once $psRoot.'/config/config.inc.php';
require_once $psRoot.'/init.php';
require_once __DIR__.'/auth.php';
require_admin_login();

$db  = Db::getInstance();
$tbl = _DB_PREFIX_.'lrofileupload_admins';

function colExistsCP(string $t, string $c): bool {
  return (bool)Db::getInstance()->getValue("SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='".pSQL(_DB_NAME_)."' AND TABLE_NAME='".pSQL($t)."' AND COLUMN_NAME='".pSQL($c)."' LIMIT 1");
}
$COL_ID    = colExistsCP($tbl,'id_admin')? 'id_admin' : (colExistsCP($tbl,'admin_id')? 'admin_id':'id_admin');
$COL_HASH  = colExistsCP($tbl,'password_hash')? 'password_hash' : (colExistsCP($tbl,'password')? 'password':'password_hash');

$flash = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  require_csrf_or_400();
  $cur = (string)Tools::getValue('current','');
  $n1  = (string)Tools::getValue('new1','');
  $n2  = (string)Tools::getValue('new2','');
  if ($n1==='' || $n1!==$n2) {
    $flash = 'Passwords do not match.';
  } else {
    $id = (int)($_SESSION['lro_admin_id'] ?? 0);
    $row= $db->getRow("SELECT * FROM `{$tbl}` WHERE `{$COL_ID}`={$id} LIMIT 1");
    if (!$row) { $flash='Account not found.'; }
    else {
      $ok = ($COL_HASH==='password_hash') ? password_verify($cur, (string)$row[$COL_HASH])
                                          : hash_equals((string)$row[$COL_HASH], md5($cur));
      if (!$ok) $flash='Current password is incorrect.';
      else {
        $newHash = ($COL_HASH==='password_hash') ? password_hash($n1, PASSWORD_DEFAULT) : md5($n1);
        $db->execute("UPDATE `{$tbl}` SET `{$COL_HASH}`='".pSQL($newHash)."' WHERE `{$COL_ID}`={$id} LIMIT 1");
        $flash = 'Password changed.';
      }
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><title>Change Password</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container py-3">
  <?php include __DIR__.'/nav.php'; ?>
  <h3 class="mb-3">Change Password</h3>
  <?php if ($flash): ?><div class="alert alert-info"><?= lro_h($flash) ?></div><?php endif; ?>
  <form method="post" class="row g-3" autocomplete="off">
    <?= csrf_input() ?>
    <div class="col-md-4">
      <label class="form-label">Current password</label>
      <input class="form-control" type="password" name="current">
    </div>
    <div class="col-md-4">
      <label class="form-label">New password</label>
      <input class="form-control" type="password" name="new1">
    </div>
    <div class="col-md-4">
      <label class="form-label">Confirm new password</label>
      <input class="form-control" type="password" name="new2">
    </div>
    <div class="col-12"><button class="btn btn-primary">Save</button></div>
  </form>
</div>
</body>
</html>
