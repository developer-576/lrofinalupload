<?php
/**
 * modules/lrofileupload/admin/nav.php
 * Lightweight, capability-aware Bootstrap nav for admin pages.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

/* ----- capability helper (masters pass) ----- */
if (!function_exists('lro_nav_has_cap')) {
  function lro_nav_has_cap(string $cap): bool {
    $isMaster = !empty($_SESSION['lro_is_master']) || !empty($_SESSION['is_master']);
    if ($isMaster) return true;

    // If your module exposes a helper, use it
    if (function_exists('lro_has_cap')) return (bool) lro_has_cap($cap);
    if (function_exists('has_cap'))     return (bool) has_cap($cap);

    // Fallback to common session flags (either plain or with lro_ prefix)
    return !empty($_SESSION[$cap]) || !empty($_SESSION['lro_'.$cap]);
  }
}

$IS_MASTER  = !empty($_SESSION['lro_is_master']) || !empty($_SESSION['is_master']);
$ADMIN_NAME = (string)($_SESSION['admin_name'] ?? $_SESSION['username'] ?? 'admin');
$CUR        = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));

/* Link builder */
function lro_nav_item(string $file, string $label, string $icon = '', ?string $cap = null, bool $masterOnly = false): array {
  return compact('file','label','icon','cap','masterOnly');
}

/* ----- define menu ----- */
/* Main */
$items_main = [
  lro_nav_item('dashboard.php',              'Dashboard',            'bi-speedometer2'),
  lro_nav_item('view_uploads.php',           'View uploads',         'bi-file-earmark-text', 'can_view_uploads'),
  lro_nav_item('credential_card_viewer.php', 'Credential cards',     'bi-person-vcard',      'can_view_credential_card'),

  // ✅ The three you asked to add:
  lro_nav_item('manual_unlock.php',          'Manual unlock',        'bi-unlock',            'can_manual_unlock'),   // masters pass
  lro_nav_item('email_settings.php',         'Email settings',       'bi-envelope-gear',     'can_manage_email'),    // masters pass
  lro_nav_item('manage_file_groups.php',     'Manage file group',    'bi-diagram-3',         'can_manage_file_groups'), // masters pass

  lro_nav_item('reasons.php',                'Rejection reasons',    'bi-x-octagon',         'can_manage_rejections'),
  lro_nav_item('logs_unified.php',           'Unified logs',         'bi-clipboard-data',    'can_view_logs'),
  lro_nav_item('master_dashboard.php',       'Master dashboard',     'bi-shield-lock',       null, true), // master-only
];

/* Right side */
$items_right = [
  lro_nav_item('change_password.php',        'Change password', 'bi-key'),
  lro_nav_item('logout.php',                 'Log out',         'bi-box-arrow-right'),
];

/* filter by capability / master flag and existence (don’t break if file missing) */
$filter = function(array $item) use ($IS_MASTER): bool {
  if (!empty($item['masterOnly']) && !$IS_MASTER) return false;
  if (!empty($item['cap']) && !lro_nav_has_cap($item['cap'])) return false;
  // If file exists, great; if not, still show (user wanted the tabs visible).
  return true;
};
$items_main  = array_values(array_filter($items_main, $filter));
$items_right = array_values(array_filter($items_right, $filter));

/* small helper for active state */
$active = function(string $file) use ($CUR): string {
  return ($CUR === $file) ? 'active' : '';
};
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<nav class="navbar navbar-expand-lg bg-white border-bottom mb-3">
  <div class="container">
    <a class="navbar-brand fw-semibold" href="dashboard.php">
      <i class="bi bi-stack"></i> LRO File Upload Admin
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#lroNav" aria-controls="lroNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="lroNav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <?php foreach ($items_main as $it): ?>
          <li class="nav-item">
            <a class="nav-link <?= $active($it['file']) ?>" href="<?= htmlspecialchars($it['file']) ?>">
              <?php if (!empty($it['icon'])): ?><i class="bi <?= htmlspecialchars($it['icon']) ?>"></i><?php endif; ?>
              <?= htmlspecialchars($it['label']) ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>

      <ul class="navbar-nav ms-auto">
        <?php if ($IS_MASTER): ?>
          <li class="nav-item me-2">
            <span class="badge text-bg-primary align-self-center">Master</span>
          </li>
        <?php endif; ?>
        <li class="nav-item me-3 align-self-center text-muted small">
          <i class="bi bi-person-circle"></i> <?= htmlspecialchars($ADMIN_NAME) ?>
        </li>
        <?php foreach ($items_right as $it): ?>
          <li class="nav-item">
            <a class="nav-link <?= $active($it['file']) ?>" href="<?= htmlspecialchars($it['file']) ?>">
              <?php if (!empty($it['icon'])): ?><i class="bi <?= htmlspecialchars($it['icon']) ?>"></i><?php endif; ?>
              <?= htmlspecialchars($it['label']) ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</nav>
