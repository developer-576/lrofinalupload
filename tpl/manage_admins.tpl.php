<?php
/**
 * modules/lrofileupload/admin/manage_admins.php
 * Drop-in fixed version with all roles: dashboard, uploads, file groups, rejections, emails, credential card, admins.
 */

require_once __DIR__ . '/session_bootstrap.php';
if (!defined('_PS_VERSION_')) {
    $psRoot = realpath(__DIR__ . '/../../../');
    require_once $psRoot . '/config/config.inc.php';
    require_once $psRoot . '/init.php';
}
require_once __DIR__ . '/helpers_log.php';
session_start();

if (empty($_SESSION['admin_id']) || !($_SESSION['is_master'] ?? false)) {
    http_response_code(403);
    die('Access denied');
}

$db = Db::getInstance();
$prefix = _DB_PREFIX_;
$table = $prefix . 'lrofileupload_admins';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['admin_id'] ?? 0);
    if ($id > 0) {
        $fields = [
            'username', 'email',
            'can_view_dashboard', 'can_view_uploads', 'can_manage_file_groups',
            'can_manage_rejections', 'can_manage_emails', 'can_view_credential_card', 'can_manage_admins',
        ];

        $values = [];
        foreach ($fields as $field) {
            $values[$field] = isset($_POST[$field]) ? 1 : 0;
        }

        $sql = "UPDATE `$table` SET
            can_view_dashboard = {$values['can_view_dashboard']},
            can_view_uploads = {$values['can_view_uploads']},
            can_manage_file_groups = {$values['can_manage_file_groups']},
            can_manage_rejections = {$values['can_manage_rejections']},
            can_manage_emails = {$values['can_manage_emails']},
            can_view_credential_card = {$values['can_view_credential_card']},
            can_manage_admins = {$values['can_manage_admins']}
            WHERE admin_id = $id";
        $db->execute($sql);
    }
    header('Location: manage_admins.php');
    exit;
}

$admins = $db->executeS("SELECT * FROM `$table` ORDER BY date_added DESC");
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Admins</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .checkbox-cell { text-align: center; vertical-align: middle; }
        .role-col { min-width: 140px; }
    </style>
</head>
<body class="container my-4">
    <h2 class="mb-4">Manage Admins</h2>
    <table class="table table-bordered table-striped align-middle">
        <thead class="table-dark">
            <tr>
                <th>Username</th>
                <th>Email</th>
                <th class="role-col">Dashboard</th>
                <th class="role-col">Uploads</th>
                <th class="role-col">File Groups</th>
                <th class="role-col">Rejections</th>
                <th class="role-col">Emails</th>
                <th class="role-col">Credential Card</th>
                <th class="role-col">Admins</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($admins as $admin): ?>
            <form method="post" action="manage_admins.php">
                <input type="hidden" name="admin_id" value="<?= (int)$admin['admin_id'] ?>">
                <tr>
                    <td><?= htmlspecialchars($admin['username']) ?></td>
                    <td><?= htmlspecialchars($admin['email']) ?></td>
                    <?php
                        $roles = [
                            'can_view_dashboard', 'can_view_uploads', 'can_manage_file_groups',
                            'can_manage_rejections', 'can_manage_emails', 'can_view_credential_card', 'can_manage_admins'
                        ];
                        foreach ($roles as $role) {
                            $checked = isset($admin[$role]) && $admin[$role] ? 'checked' : '';
                            echo "<td class='checkbox-cell'><input type='checkbox' name='$role' $checked></td>";
                        }
                    ?>
                    <td><button class="btn btn-sm btn-success">Save</button></td>
                </tr>
            </form>
        <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
