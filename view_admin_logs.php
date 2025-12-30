<?php
require_once __DIR__ . '/session_bootstrap.php';

require_once dirname(__FILE__, 4) . '/config/config.inc.php';
require_once dirname(__FILE__, 4) . '/init.php';
require_once __DIR__ . '/auth.php';
require_master_login();

$prefix = _DB_PREFIX_;
$db = Db::getInstance();

$logs = $db->executeS("SELECT * FROM {$prefix}lrofileupload_admin_logs ORDER BY created_at DESC LIMIT 100");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Action Logs</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container py-4">
    <?php include 'nav.php'; ?>
    <h3>Admin Action Logs</h3>

    <table class="table table-bordered">
        <thead class="table-dark">
            <tr>
                <th>#</th>
                <th>Admin Username</th>
                <th>Action</th>
                <th>Description</th>
                <th>Target ID</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= (int)$log['id_log'] ?></td>
                    <td><?= htmlspecialchars($log['admin_username']) ?></td>
                    <td><?= htmlspecialchars($log['action']) ?></td>
                    <td><?= htmlspecialchars($log['description'] ?? '-') ?></td>
                    <td><?= (int)$log['target_id'] ?></td>
                    <td><?= htmlspecialchars($log['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
</body>
</html>
