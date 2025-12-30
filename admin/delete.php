<?php
require_once __DIR__ . '/session_bootstrap.php';

require_once(__DIR__ . '/auth.php');
require_admin_login(true);
require_once(__DIR__ . '/config.php');

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = (int) $_GET['id'];
    $stmt = $pdo->prepare("UPDATE " . _DB_PREFIX_ . "lrofileupload_uploads SET status = 'deleted', rejection_reason = NULL WHERE id_upload = ?");
    $stmt->execute([$id]);
    header('Location: master_dashboard.php?success=deleted');
    exit;
} else {
    header('Location: master_dashboard.php?error=invalid_delete');
    exit;
}
