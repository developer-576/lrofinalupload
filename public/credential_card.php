<?php
// modules/lrofileupload/public/credential_card.php
declare(strict_types=1);

// Error visibility: verbose only in dev
if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}

// ---- Bootstrap PrestaShop ----
$psRoot = dirname(__FILE__, 5);            // /modules/lrofileupload/public -> PS root
require_once $psRoot . '/config/config.inc.php';
require_once $psRoot . '/init.php';

// ---- Require module admin auth (move this page if you later relocate to /admin/) ----
$moduleDir = dirname(__FILE__, 2);         // /modules/lrofileupload
$authFile  = $moduleDir . '/admin/auth.php';
if (is_file($authFile)) {
    require_once $authFile;
    if (function_exists('require_admin_login')) {
        require_admin_login();             // or require_master_login();
    }
} else {
    // Fallback: at least ensure employee is logged in
    $ctx = Context::getContext();
    if (!$ctx || !$ctx->employee || !(int)$ctx->employee->id) {
        header('HTTP/1.1 403 Forbidden'); exit('Admin login required');
    }
}

// ---- Helpers ----
$db     = Db::getInstance();
$prefix = _DB_PREFIX_;
$tblCustomer = $prefix.'customer';
$tblUploads  = $prefix.'lrofileupload_uploads';
$tblGroups   = $prefix.'lrofileupload_product_groups';

function hasColumn(string $table, string $column): bool {
    $q = "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA='" . pSQL(_DB_NAME_) . "'
            AND TABLE_NAME='" . pSQL($table) . "'
            AND COLUMN_NAME='" . pSQL($column) . "'";
    return (bool)Db::getInstance()->getValue($q);
}
function esc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function labelFromKey(string $k): string { return ucwords(str_replace('_',' ', $k)); }

// ---- Input ----
$search = trim((string)Tools::getValue('search', ''));

// ---- Find customer by email / custom ID / full name ----
$customer = null;
if ($search !== '') {
    // by email
    if (strpos($search, '@') !== false) {
        $customer = $db->getRow('SELECT id_customer, firstname, lastname FROM '.$GLOBALS['tblCustomer'].' WHERE email="'.pSQL($search).'" LIMIT 1');
    }
    // by custom id_number (if column exists)
    if (!$customer && hasColumn($GLOBALS['tblCustomer'], 'id_number')) {
        $customer = $db->getRow('SELECT id_customer, firstname, lastname FROM '.$GLOBALS['tblCustomer'].' WHERE id_number="'.pSQL($search).'" LIMIT 1');
    }
    // by full name "First Last"
    if (!$customer && strpos($search, ' ') !== false) {
        list($first, $last) = array_map('trim', explode(' ', $search, 2));
        if ($first !== '' && $last !== '') {
            $customer = $db->getRow(
                'SELECT id_customer, firstname, lastname FROM '.$GLOBALS['tblCustomer'].
                ' WHERE firstname="'.pSQL($first).'" AND lastname="'.pSQL($last).'" LIMIT 1'
            );
        }
    }
}

// ---- Requirements you want to display ----
$requirements = [
    'proof_of_address' => 'Proof of Address',
    'id_document'      => 'ID Document',
    'selfie'           => 'Selfie',
    'red_thumbprint'   => 'Red Right Thumbprint',
];

// ---- Capability: can we use requirement_name? ----
$hasRequirementName = hasColumn($tblUploads, 'requirement_name');
$hasIdGroup         = hasColumn($tblUploads, 'id_group');
$hasIdRequirement   = hasColumn($tblUploads, 'id_requirement');
$hasGroupName       = hasColumn($tblGroups,  'group_name');

// ---- Fetch latest upload for a requirement ----
/**
 * Return latest upload row for given customer and requirement key/label.
 * Priorities:
 *   1) uploads.requirement_name = key (if column present)
 *   2) join groups by id_group/id_requirement and match group_name = label (if group_name exists)
 *   3) null (missing)
 */
function latestUploadForRequirement(
    int $idCustomer,
    string $key,
    string $label
) {
    $db = Db::getInstance();
    $tu = $GLOBALS['tblUploads'];
    $tg = $GLOBALS['tblGroups'];

    // 1) requirement_name strategy
    if ($GLOBALS['hasRequirementName']) {
        $row = $db->getRow(
            'SELECT id_upload, id_customer, id_group, id_requirement, file_name, original_name, status, uploaded_at
             FROM '.$tu.'
             WHERE id_customer='.$idCustomer.' AND requirement_name="'.pSQL($key).'"
             ORDER BY uploaded_at DESC, id_upload DESC
             LIMIT 1'
        );
        if ($row) return $row;
    }

    // 2) join product_groups on id_group or id_requirement and match label
    if ($GLOBALS['hasGroupName'] && ($GLOBALS['hasIdGroup'] || $GLOBALS['hasIdRequirement'])) {
        $on = $GLOBALS['hasIdGroup'] ? 'u.id_group=g.id_group' : 'u.id_requirement=g.id_group';
        $row = $db->getRow(
            'SELECT u.id_upload, u.id_customer, u.id_group, u.id_requirement, u.file_name, u.original_name, u.status, u.uploaded_at
             FROM '.$tu.' u
             INNER JOIN '.$tg.' g ON '.$on.'
             WHERE u.id_customer='.$idCustomer.' AND g.group_name="'.pSQL($label).'"
             ORDER BY u.uploaded_at DESC, u.id_upload DESC
             LIMIT 1'
        );
        if ($row) return $row;
    }

    return null;
}

// ---- Build status map & links if we have a customer ----
$statuses = [];
$files    = [];  // id_upload -> link
if ($customer) {
    $idCustomer = (int)$customer['id_customer'];
    foreach ($requirements as $key => $label) {
        $row = latestUploadForRequirement($idCustomer, $key, $label);
        if (!$row) {
            $statuses[$key] = '❌ Missing';
            continue;
        }
        $st = (string)($row['status'] ?? 'pending');
        if ($st === 'approved') {
            $statuses[$key] = '✅ Approved';
        } elseif ($st === 'rejected') {
            $statuses[$key] = '❌ Rejected';
        } else {
            $statuses[$key] = '⏳ Pending';
        }

        // Admin preview link: guarded on admin side; avoid exposing raw paths
        $files[$key] = '/modules/lrofileupload/admin/serve_file.php?id_upload='.(int)$row['id_upload'];
    }
}

?><!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Credential Card</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Optional: Bootstrap for quick layout -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .card-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:1rem; }
    </style>
</head>
<body class="p-4 bg-light">
<div class="container">
    <h2 class="mb-4">Credential Card Lookup (Admin)</h2>

    <form method="get" class="mb-4">
        <div class="input-group">
            <input type="text" name="search" class="form-control" placeholder="Search by Email, ID or Full Name" value="<?php echo esc($search); ?>" required>
            <button class="btn btn-primary" type="submit">Search</button>
        </div>
    </form>

    <?php if ($search === ''): ?>
        <div class="alert alert-info">Enter email, a custom ID (if configured), or "First Last".</div>
    <?php elseif (!$customer): ?>
        <div class="alert alert-danger">No customer found for "<?php echo esc($search); ?>"</div>
    <?php else: ?>
        <div class="card mb-3">
            <div class="card-header bg-dark text-white">
                <?php echo esc($customer['firstname'].' '.$customer['lastname']); ?> – Credential Card
            </div>
            <div class="card-body card-grid">
                <?php foreach ($requirements as $key => $label): ?>
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title"><?php echo esc($label); ?></h5>
                            <p>Status: <strong><?php echo esc($statuses[$key] ?? '❓'); ?></strong></p>
                            <?php if (!empty($files[$key])): ?>
                                <a href="<?php echo esc($files[$key]); ?>" target="_blank" class="btn btn-outline-primary btn-sm">View</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
            $allApproved = count(array_filter($statuses, function($s){ return $s === '✅ Approved'; })) === count($requirements);
        ?>
        <div class="alert <?php echo $allApproved ? 'alert-success' : 'alert-warning'; ?>">
            <?php echo $allApproved
                ? '✅ All documents approved. Credential is VERIFIED.'
                : '⏳ Credential is still pending. Some documents are missing or not approved.'; ?>
        </div>
    <?php endif; ?>

    <p class="text-muted mt-4 mb-0">
        <small>Tip: This page is admin-only. For customer downloads, use the module download URL:
        <code>/module/lrofileupload/download?id_upload={ID}</code> (customer must own the file).</small>
    </p>
</div>
</body>
</html>
