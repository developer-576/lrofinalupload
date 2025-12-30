<?php
// modules/lrofileupload/admin/manual_unlock.php
declare(strict_types=1);

/* ---------------- Errors (dev) ---------------- */
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

/* ---------------- PrestaShop bootstrap ---------------- */
(function () {
    $dir = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        if (file_exists($dir.'/config/config.inc.php') && file_exists($dir.'/init.php')) {
            require_once $dir.'/config/config.inc.php';
            require_once $dir.'/init.php';
            return;
        }
        $dir = dirname($dir);
    }
    $root = dirname(__DIR__, 3);
    if (file_exists($root.'/config/config.inc.php') && file_exists($root.'/init.php')) {
        require_once $root.'/config/config.inc.php';
        require_once $root.'/init.php';
        return;
    }
    header('Content-Type: text/plain; charset=utf-8', true, 500);
    exit('Could not locate PrestaShop root.');
})();

/* ---------------- Module session & auth ---------------- */
require_once __DIR__.'/session_bootstrap.php';
require_once __DIR__.'/auth.php';
require_master_login(); // master-only

/* ---------------- Helpers ---------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function self_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path   = strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?');
    return $scheme.'://'.$host.$path;
}
function redirect_here(): void {
    header('Location: '.self_url(), true, 303);
    exit;
}

/* ---------------- DB handles / table names ---------------- */
$db   = Db::getInstance();
$P    = _DB_PREFIX_;
$tblUnlocks = $P.'lrofileupload_manual_unlocks';
$tblCust    = $P.'customer';

/* ---- Introspection helpers (avoid unknown-column errors) ---- */
$esc = static function(string $s): string { return pSQL($s, true); };

$tableExists = static function(string $table) use ($db, $esc): bool {
    return (bool)$db->getValue("
        SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = '".$esc($table)."'
    ");
};
$columnExists = static function(string $table, string $col) use ($db, $esc): bool {
    return (bool)$db->getValue("
        SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name   = '".$esc($table)."'
          AND column_name  = '".$esc($col)."'
    ");
};

/* ---- Find the groups table and a safe label expression ---- */
$possibleTables = [
    $P.'lrofileupload_groups',
    $P.'lrofileupload_product_groups',
];
$tblGroups = '';
foreach ($possibleTables as $t) {
    if ($tableExists($t)) { $tblGroups = $t; break; }
}
if ($tblGroups === '') {
    header('Content-Type: text/plain; charset=utf-8', true, 500);
    exit('Could not find a groups table (looked for lrofileupload_groups / lrofileupload_product_groups).');
}

$candidateLabelCols = ['group_name', 'name', 'title', 'label'];
$labelCols = [];
foreach ($candidateLabelCols as $c) {
    if ($columnExists($tblGroups, $c)) $labelCols[] = $c;
}
$hasIdGroup = $columnExists($tblGroups, 'id_group');
$labelExprParts = [];
foreach ($labelCols as $c) {
    $labelExprParts[] = 'pg.`'.$c.'`';
}
$labelExpr = $labelExprParts ? ('COALESCE('.implode(',', $labelExprParts).','.( $hasIdGroup ? "CONCAT('Group #', pg.id_group)" : "'Group'" ).')') : ( $hasIdGroup ? "CONCAT('Group #', pg.id_group)" : "'Group'" );

/* ---------------- Ensure unique index exists (quietly) ---------------- */
try {
    $hasIdx = (int)$db->getValue("
        SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name   = '".$esc($tblUnlocks)."'
          AND index_name   = 'uniq_customer_group'
    ");
    if ($hasIdx === 0) {
        $db->execute("CREATE UNIQUE INDEX `uniq_customer_group`
                      ON `{$tblUnlocks}` (`id_customer`,`id_group`)");
    }
} catch (Throwable $e) {
    // ignore if no privilege; page still works
}

/* ---------------- CSRF ---------------- */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf_token'];
$require_csrf = static function(string $from = 'post') use ($CSRF) {
    $tk = (string)(($from === 'get') ? ($_GET['csrf_token'] ?? '') : ($_POST['csrf_token'] ?? ''));
    if (!$tk || !hash_equals($CSRF, $tk)) { http_response_code(400); exit('Invalid CSRF token'); }
};

/* ---------------- Current module admin id ---------------- */
function lro_current_admin_id(): int { return (int)($_SESSION['lro_admin_id'] ?? 0); }

/* ---------------- AJAX: customer quick search ---------------- */
if (($_GET['ajax'] ?? '') === 'customers') {
    header('Content-Type: application/json; charset=utf-8');
    $require_csrf('get');
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') { echo json_encode(['ok'=>true,'items'=>[]]); exit; }

    $like = '%'.pSQL($q, true).'%';
    $rows = $db->executeS("
        SELECT id_customer, firstname, lastname, email
        FROM `{$tblCust}`
        WHERE email LIKE '{$like}' OR firstname LIKE '{$like}' OR lastname LIKE '{$like}'
        ORDER BY id_customer DESC
        LIMIT 20
    ") ?: [];
    echo json_encode(['ok'=>true,'items'=>$rows]); exit;
}

/* ---------------- POST actions ---------------- */
$flash = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $require_csrf('post');
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add') {
        $id_customer = (int)($_POST['id_customer'] ?? 0);
        $groups      = $_POST['id_group'] ?? [];
        $groups      = is_array($groups) ? array_values(array_unique(array_filter(array_map('intval', $groups)))) : [ (int)$groups ];
        $days        = max(0, (int)($_POST['days'] ?? 0));

        if ($id_customer <= 0 || !$groups) {
            $flash = 'Please choose a customer and at least one group.';
        } else {
            $now = date('Y-m-d H:i:s');
            $by  = lro_current_admin_id();
            $exp = $days > 0 ? date('Y-m-d H:i:s', time() + $days*86400) : null;

            foreach ($groups as $gid) {
                // store ONLY ids; no names
                $sql = "INSERT INTO `{$tblUnlocks}`
                            (`id_customer`,`id_group`,`unlocked_at`,`unlocked_by`,`is_active`,`expires_at`)
                        VALUES
                            (".(int)$id_customer.", ".(int)$gid.", '".pSQL($now, true)."', ".($by ?: "NULL").", 1, ".($exp ? "'".pSQL($exp, true)."'" : "NULL").")
                        ON DUPLICATE KEY UPDATE
                            `unlocked_at` = VALUES(`unlocked_at`),
                            `unlocked_by` = VALUES(`unlocked_by`),
                            `is_active`   = 1,
                            `expires_at`  = VALUES(`expires_at`)";
                $db->execute($sql);
            }
            redirect_here();
        }
    }

    if ($action === 'deactivate') {
        $id_unlock = (int)($_POST['id_unlock'] ?? 0);
        if ($id_unlock > 0) {
            $db->update('lrofileupload_manual_unlocks', ['is_active' => 0], 'id_unlock='.(int)$id_unlock, 1, true, true);
            redirect_here();
        }
        $flash = 'Bad unlock id.';
    }

    if ($action === 'delete') {
        $id_unlock = (int)($_POST['id_unlock'] ?? 0);
        if ($id_unlock > 0) {
            $db->delete('lrofileupload_manual_unlocks', 'id_unlock='.(int)$id_unlock, 1, true, true);
            redirect_here();
        }
        $flash = 'Bad unlock id.';
    }
}

/* ---------------- Data for UI ---------------- */
// Build a safe label expression for SELECT lists (alias as grp_label everywhere)
$grpLabelExpr = $labelExpr . ' AS grp_label';

// Groups list
$groups = $db->executeS("
  SELECT ".($hasIdGroup ? 'id_group,' : '0 AS id_group,')."
         ".$grpLabelExpr."
  FROM `{$tblGroups}` pg
  ORDER BY 1
") ?: [];

// Existing unlocks (JOIN to show names; we only store ids)
$rows = $db->executeS("
    SELECT
        mu.id_unlock,
        mu.id_customer,
        mu.id_group,
        mu.unlocked_at,
        mu.expires_at,
        mu.is_active,
        c.firstname, c.lastname, c.email,
        ".$grpLabelExpr."
    FROM `{$tblUnlocks}` mu
    LEFT JOIN `{$tblCust}`   c  ON c.id_customer = mu.id_customer
    LEFT JOIN `{$tblGroups}` pg ON pg.id_group   = mu.id_group
    ORDER BY mu.id_unlock DESC
") ?: [];

/* ---------------- HTML ---------------- */
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Manual Upload Unlocks</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="csrf" content="<?= h($CSRF) ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#f7f9fc; }
    .page-title { font-weight:600; }
    .table-sm > :not(caption) > * > * { padding-top:.45rem; padding-bottom:.45rem; }
    .chip { display:inline-block; padding:.15rem .45rem; border:1px solid #e5e7eb; border-radius:.4rem; font-size:.8rem; color:#444; background:#fafafa; }
    #cust_results a { font-size:.92rem; }
    #cust_results small { color:#6b7280; }
  </style>
</head>
<body class="py-4">
<div class="container">
  <?php if (is_file(__DIR__.'/nav.php')) include __DIR__.'/nav.php'; ?>

  <h3 class="page-title mb-3">Manual Upload Unlocks</h3>

  <?php if ($flash): ?><div class="alert alert-warning"><?= h($flash) ?></div><?php endif; ?>

  <div class="card mb-4">
    <div class="card-header">Add Unlock</div>
    <div class="card-body">
      <form method="post" class="row g-2" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
        <input type="hidden" name="action" value="add">

        <!-- Customer search -->
        <div class="col-md-5">
          <label class="form-label">Customer</label>
          <div class="position-relative">
            <input type="text" id="cust_search" class="form-control" placeholder="Type name or email…" autocomplete="off">
            <input type="hidden" name="id_customer" id="cust_id" value="">
            <div id="cust_results" class="list-group position-absolute w-100 bg-white border rounded shadow-sm"
                 style="z-index:1000; display:none; max-height:260px; overflow:auto;"></div>
          </div>
          <div class="form-text">Start typing: first/last name or email. Click a result to select.</div>
        </div>

        <!-- Groups -->
        <div class="col-md-5">
          <label class="form-label">Groups</label>
          <select name="id_group[]" class="form-select" multiple required>
            <?php foreach ($groups as $g): ?>
              <option value="<?= (int)$g['id_group'] ?>"><?= h($g['grp_label']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Hold Ctrl/Cmd to select more than one.</div>
        </div>

        <!-- Optional expiry days -->
        <div class="col-md-2">
          <label class="form-label">Days (optional)</label>
          <input type="number" name="days" class="form-control" min="0" placeholder="0">
          <div class="form-text">Sets <code>expires_at</code> if provided.</div>
        </div>

        <div class="col-12">
          <button class="btn btn-primary">Create Unlock(s)</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
      <span>Existing Unlocks</span>
      <div class="small text-muted">
        <span class="chip">stores: id_customer, id_group, unlocked_at, expires_at, is_active</span>
      </div>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm table-striped mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th style="width:80px">ID</th>
              <th style="width:260px">Customer</th>
              <th>Group</th>
              <th style="width:170px">Created</th>
              <th style="width:170px">Expires</th>
              <th style="width:100px">Active</th>
              <th style="width:200px">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= (int)$r['id_unlock'] ?></td>
              <td>
                <div><strong>#<?= (int)$r['id_customer'] ?></strong></div>
                <div class="text-muted small"><?= h(trim(($r['firstname'] ?? '').' '.($r['lastname'] ?? ''))) ?></div>
                <?php if (!empty($r['email'])): ?><div class="text-muted small"><?= h($r['email']) ?></div><?php endif; ?>
              </td>
              <td><?= (int)$r['id_group'] ?> — <?= h($r['grp_label'] ?? '') ?></td>
              <td><?= h($r['unlocked_at'] ?? '') ?></td>
              <td><?= h($r['expires_at'] ?? '') ?></td>
              <td>
                <?php $active = (int)($r['is_active'] ?? 1) === 1; ?>
                <span class="badge <?= $active ? 'bg-success' : 'bg-secondary' ?>"><?= $active ? 'Yes' : 'No' ?></span>
              </td>
              <td class="d-flex gap-1">
                <?php if ((int)($r['is_active'] ?? 1) === 1): ?>
                  <form method="post" onsubmit="return confirm('Deactivate this unlock?');">
                    <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
                    <input type="hidden" name="action" value="deactivate">
                    <input type="hidden" name="id_unlock" value="<?= (int)$r['id_unlock'] ?>">
                    <button class="btn btn-outline-warning btn-sm">Deactivate</button>
                  </form>
                <?php endif; ?>
                <form method="post" onsubmit="return confirm('Delete this unlock?');">
                  <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id_unlock" value="<?= (int)$r['id_unlock'] ?>">
                  <button class="btn btn-outline-danger btn-sm">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; if (!$rows): ?>
            <tr><td colspan="7" class="text-center text-muted">No manual unlocks.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
const CSRF = <?= json_encode($CSRF) ?>;
$(function(){
  const $box = $('#cust_results'), $input = $('#cust_search'), $hidden = $('#cust_id');
  let t = null;

  $input.on('input focus', function(){
    clearTimeout(t);
    const q = $input.val().trim();
    if (q.length < 2) { $box.hide().empty(); return; }
    t = setTimeout(() => {
      $.get(window.location.pathname, { ajax:'customers', q, csrf_token: CSRF }, function(resp){
        $box.empty();
        if (!resp || !resp.ok || !resp.items) { $box.hide(); return; }
        resp.items.forEach(r => {
          const name = (r.firstname||'') + ' ' + (r.lastname||'');
          const $a = $('<a href="#" class="list-group-item list-group-item-action"></a>');
          $a.text(`#${r.id_customer} — ${name.trim()} (${r.email||''})`);
          $a.on('click', function(e){
            e.preventDefault();
            $hidden.val(r.id_customer);
            $input.val(`#${r.id_customer} — ${name.trim()} (${r.email||''})`);
            $box.hide().empty();
          });
          $box.append($a);
        });
        $box.show();
      }, 'json');
    }, 200);
  });

  $(document).on('click', function(e){
    if (!$(e.target).closest('#cust_search, #cust_results').length) $box.hide();
  });
});
</script>
</body>
</html>
