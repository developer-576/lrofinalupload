<?php
require_once __DIR__ . '/session_bootstrap.php';

session_start();

require_once dirname(__FILE__, 4) . '/config/config.inc.php';
require_once dirname(__FILE__, 4) . '/init.php';
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json');

require_admin_login();

$id_upload = (int)($_POST['file_id'] ?? 0);
if ($id_upload <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid or missing file ID']);
    exit;
}

try {
    $db = Db::getInstance();
    $prefix = _DB_PREFIX_;

    $success = $db->update(
        $prefix . 'lrofileupload_uploads',
        [
            'status' => 'approved',
            'action_by' => (int)$_SESSION['admin_id'],
            'action_date' => date('Y-m-d H:i:s')
        ],
        'id_upload = ' . (int)$id_upload
    );

    if ($success) {
        echo json_encode(['success' => true, 'message' => 'File approved successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update database']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
exit;
