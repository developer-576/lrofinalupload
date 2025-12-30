<?php
require_once __DIR__ . '/session_bootstrap.php';

require_once('../../config/db.php');
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $approveEmail = $_POST['approve_email'];
    $rejectEmail = $_POST['reject_email'];

    $update = $conn->prepare("REPLACE INTO psfc_lrofileupload_config (config_key, config_value) VALUES (?, ?), (?, ?)");
    $approveKey = 'approval_email_sender';
    $rejectKey = 'rejection_email_sender';
    $update->bind_param("ssss", $approveKey, $approveEmail, $rejectKey, $rejectEmail);
    $update->execute();
}

$result = $conn->query("SELECT config_key, config_value FROM psfc_lrofileupload_config");
$config = [];
while ($row = $result->fetch_assoc()) {
    $config[$row['config_key']] = $row['config_value'];
}
?>

<form method="POST">
  <label>Approval Email Sender:</label>
  <input type="email" name="approve_email" value="<?= htmlspecialchars($config['approval_email_sender'] ?? '') ?>" required><br>
  <label>Rejection Email Sender:</label>
  <input type="email" name="reject_email" value="<?= htmlspecialchars($config['rejection_email_sender'] ?? '') ?>" required><br>
  <button type="submit">Save</button>
</form>
