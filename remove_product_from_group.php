<?php
require_once __DIR__ . '/session_bootstrap.php';

require_once(__DIR__ . '/auth.php');
require_admin_login();
require_once(__DIR__ . '/config.php');

$product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
$group_id = isset($_POST['group_id']) ? (int)$_POST['group_id'] : 0;

if (!$product_id || !$group_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing product or group ID.']);
    exit;
}

$stmt = $pdo->prepare("DELETE FROM " . _DB_PREFIX_ . "lrofileupload_group_products WHERE id_product = ? AND id_group = ?");
if ($stmt->execute([$product_id, $group_id])) {
    echo json_encode(['status' => 'success', 'message' => 'Product removed from group.']);
} else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error.']);
}
?>
