<?php
require_once dirname(__FILE__) . '/../../config/config.inc.php';
require_once dirname(__FILE__) . '/../../init.php';

$context = Context::getContext();
$customer_id = $context->customer->id;

if (!isset($_GET['id_upload'])) {
    die('Invalid request.');
}

$id_upload = (int)$_GET['id_upload'];

$sql = "
    SELECT file_path, display_name
    FROM psfc_lrofileupload_uploads
    WHERE id_upload = $id_upload
      AND customer_id = $customer_id
    LIMIT 1
";

$file = Db::getInstance()->getRow($sql);

if (!$file) {
    die('File not found.');
}

$full_path = $file['file_path'];

if (!file_exists($full_path)) {
    die('File missing on server.');
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($file['display_name']) . '"');
header('Content-Length: ' . filesize($full_path));
readfile($full_path);
exit;
