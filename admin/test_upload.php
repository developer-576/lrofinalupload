<?php
require_once __DIR__ . '/session_bootstrap.php';

require_once(__DIR__ . '/auth.php');
require_admin_login();
require_once(__DIR__ . '/config.php');

$group_id = isset($_POST['group_id']) ? (int)$_POST['group_id'] : 0;
$product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;

if (!$group_id || !$product_id || !isset($_FILES['test_file'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required data.']);
    exit;
}

$allowed_types = ['application/pdf', 'image/jpeg'];
$file = $_FILES['test_file'];
if (!in_array($file['type'], $allowed_types)) {
    http_response_code(415);
    echo json_encode(['status' => 'error', 'message' => 'Invalid file type. Only PDF and JPEG are allowed.']);
    exit;
}

$upload_dir = __DIR__ . '/../test_uploads/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0775, true);
}

$filename = basename($file['name']);
$target_path = $upload_dir . time() . '_' . preg_replace('/[^a-zA-Z0-9\._-]/', '_', $filename);

if (move_uploaded_file($file['tmp_name'], $target_path)) {
    echo json_encode(['status' => 'success', 'message' => 'Test file uploaded successfully.']);
} else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to upload file.']);
}
?>
