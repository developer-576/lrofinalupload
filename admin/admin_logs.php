<?php
require_once __DIR__ . '/session_bootstrap.php';

session_start();

if (!isset($_SESSION['admin_id'])) {
    die("Unauthorized access. Please <a href='admin_login.php'>login</a>.");
}

$params = require __DIR__ . '/../config/parameters.php';

$servername = $params['parameters']['database_host'];
$dbUsername = $params['parameters']['database_user'];
$dbPassword = $params['parameters']['database_password'];
$dbName     = $params['parameters']['database_name'];
$prefix     = $params['parameters']['database_prefix'];

$mysqli = new mysqli($servername, $dbUsername, $dbPassword, $dbName);
if ($mysqli->connect_error) {
    die('MySQLi Connect Error (' . $mysqli->connect_errno . ') '. $mysqli->connect_error);
}

$sql = "SELECT l.*, a.username AS admin_name FROM {$prefix}lrofileupload_admin_logs l
        LEFT JOIN {$prefix}lrofileupload_admins a ON l.admin_id = a.id
        ORDER BY l.timestamp DESC";
$result = $mysqli->query($sql);

$logs = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $logs[] = $row;
    }
}
$mysqli->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Logs</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .menu-bar { background: #007bff; padding: 10px; }
        .menu-bar a { color: white; margin-right: 15px; text-decoration: none; font-weight: bold; }
    </style>
</head>
<body>

<div class="menu-bar">
    <a href="master_dashboard.php">Dashboard</a>
    <a href="manage_admins.php">Manage Admins</a>
    <a href="manual_unlocks.php">Manual Unlocks</a>
    <a href="manage_file_groups.php">Manage File Groups</a>
    <a href="rejection_reasons.php">Rejection Reasons</a>
    <a href="view_logs.php">View Logs</a>
    <a href="logout.php">Logout</a>
</div>

<div class="container mt-4">
    <h2>📜 Admin Logs</h2>

    <table class="table table-striped">
        <thead>
            <tr>
                <th>Timestamp</th>
                <th>Admin</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
            <tr>
                <td><?= htmlspecialchars($log['timestamp']) ?></td>
                <td><?= !empty($log['admin_name']) ? htmlspecialchars($log['admin_name']) : 'Unknown' ?></td>
                <td><?= htmlspecialchars($log['action']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

</body>
</html>
