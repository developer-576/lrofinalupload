<?php
require_once __DIR__ . '/session_bootstrap.php';

require_once(__DIR__ . '/auth.php');
require_admin_login(true);
require_once(__DIR__ . '/config.php');

$group_id = isset($_POST['group_id']) ? (int)$_POST['group_id'] : 0;

if ($group_id <= 0 || !isset($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request.']);
    exit;
}

// Clean old files (> 7 days)
$uploadDir = __DIR__ . "/test_uploads/$group_id/";
if (file_exists($uploadDir)) {
    foreach (glob($uploadDir . '*') as $file) {
        if (is_file($file) && time() - filemtime($file) > 7 * 86400) {
            unlink($file);
        }
    }
}

// Fetch allowed file types
$stmt = $pdo->prepare("SELECT type FROM psfc_lrofileupload_requirements WHERE id_group = ?");
$stmt->execute([$group_id]);
$allowed_types = array_unique(array_map('strtolower', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'type')));

$file = $_FILES['file'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($ext, $allowed_types)) {
    http_response_code(415);
    echo json_encode(['error' => "File type .$ext not allowed."]);
    exit;
}

// Save to folder
if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);

$filename = uniqid() . '_' . basename($file['name']);
$target = $uploadDir . $filename;

if (move_uploaded_file($file['tmp_name'], $target)) {
    // Log it
    $pdo->prepare("INSERT INTO psfc_lrofileupload_test_uploads (id_group, filename, uploaded_by) VALUES (?, ?, ?)")
        ->execute([$group_id, $filename, $_SESSION['admin_username']]);

    echo json_encode([
        'success' => "Upload successful: $filename",
        'files' => array_values(array_diff(scandir($uploadDir), ['.', '..']))
    ]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save file.']);
}
