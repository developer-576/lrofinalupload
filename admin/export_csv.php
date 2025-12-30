<?php
require_once __DIR__ . '/session_bootstrap.php';

session_start();

if (!isset($_SESSION['admin_id']) || !$_SESSION['is_master']) {
    die("Unauthorized access.");
}

$params = require __DIR__ . '/../../../app/config/parameters.php';

$servername = $params['parameters']['database_host'];
$dbUsername = $params['parameters']['database_user'];
$dbPassword = $params['parameters']['database_password'];
$dbName     = $params['parameters']['database_name'];
$prefix     = $params['parameters']['database_prefix'];

$mysqli = new mysqli($servername, $dbUsername, $dbPassword, $dbName);
if ($mysqli->connect_error) {
    die('MySQLi Connect Error (' . $mysqli->connect_errno . ') '. $mysqli->connect_error);
}

// Filters
$status = $_GET['status'] ?? '';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';

$conditions = [];
if ($status !== '') {
    $statusSafe = $mysqli->real_escape_string($status);
    $conditions[] = "u.status = '{$statusSafe}'";
}

if ($startDate !== '') {
    $startDateSafe = $mysqli->real_escape_string($startDate);
    $conditions[] = "DATE(u.uploaded_at) >= '{$startDateSafe}'";
}

if ($endDate !== '') {
    $endDateSafe = $mysqli->real_escape_string($endDate);
    $conditions[] = "DATE(u.uploaded_at) <= '{$endDateSafe}'";
}

$where = '';
if (count($conditions) > 0) {
    $where = 'WHERE ' . implode(' AND ', $conditions);
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=uploads_export.csv');

$output = fopen('php://output', 'w');
fputcsv($output, ['File ID', 'Customer', 'File Name', 'Status', 'Uploaded At']);

$sql = "SELECT u.id_upload, CONCAT(c.firstname, ' ', c.lastname) AS customer, u.file_name, u.status, u.uploaded_at
        FROM {$prefix}lrofileupload_uploads u
        LEFT JOIN {$prefix}customer c ON u.id_customer = c.id_customer
        $where
        ORDER BY u.uploaded_at DESC";

$result = $mysqli->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['id_upload'],
            $row['customer'],
            $row['file_name'],
            $row['status'],
            $row['uploaded_at']
        ]);
    }
}

fclose($output);
$mysqli->close();
exit;
?>
