<?php
require_once dirname(__DIR__) . '/config/config.inc.php';

if (!Context::getContext()->customer->isLogged()) {
    die(json_encode(['success' => false, 'message' => 'Please log in.']));
}

$id_customer = (int)Context::getContext()->customer->id;
$file = $_FILES['file'] ?? null;

if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    die(json_encode(['success' => false, 'message' => 'Upload error.']));
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['pdf', 'jpg', 'jpeg'])) {
    die(json_encode(['success' => false, 'message' => 'Invalid file type.']));
}

$filename = uniqid() . '.' . $ext;
$upload_dir = _PS_UPLOAD_DIR_ . 'lrofileupload/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

if (!move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
    die(json_encode(['success' => false, 'message' => 'Could not save file.']));
}

Db::getInstance()->insert('lrofileupload_files', [
    'id_customer' => $id_customer,
    'filename' => pSQL($filename),
    'original_name' => pSQL($file['name']),
    'status' => 'pending',
    'date_add' => date('Y-m-d H:i:s')
]);

die(json_encode(['success' => true, 'message' => 'Upload complete.']));
