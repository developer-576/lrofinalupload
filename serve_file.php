<?php
/**
 * Secure file download for LRO File Upload module.
 * Accessed via history controller which includes this file.
 * Uses id_upload and enforces that the file belongs to the logged-in customer.
 */

use PrestaShop\PrestaShop\Adapter\Entity\Db;
use PrestaShop\PrestaShop\Adapter\Entity\Context;

if (!defined('_PS_VERSION_')) {
    require dirname(__FILE__) . '/../../config/config.inc.php';
    require dirname(__FILE__) . '/../../init.php';
}

$context  = Context::getContext();
$customer = $context->customer;

if (!$customer || !$customer->isLogged()) {
    header('HTTP/1.1 403 Forbidden');
    echo 'You must be logged in to access this file.';
    exit;
}

// The history controller passes ?download={id_upload}
$id_upload = (int) Tools::getValue('download', 0);
if ($id_upload <= 0) {
    header('HTTP/1.1 400 Bad Request');
    echo 'Invalid file reference.';
    exit;
}

// Fetch row
$sql = '
    SELECT id_upload, id_customer, file_name, file_path, original_name
    FROM `' . _DB_PREFIX_ . 'lrofileupload_uploads`
    WHERE id_upload = ' . $id_upload . '
      AND is_active = 1
    LIMIT 1
';

$row = Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow($sql);

if (!$row) {
    header('HTTP/1.1 404 Not Found');
    echo 'File not found.';
    exit;
}

if ((int) $row['id_customer'] !== (int) $customer->id) {
    header('HTTP/1.1 403 Forbidden');
    echo 'You are not allowed to access this file.';
    exit;
}

// Determine absolute path
$psRoot  = _PS_ROOT_DIR_;                               // /home/.../public_html
$baseDir = rtrim(dirname($psRoot), '/') . '/uploads_lrofileupload';

$filePath = $row['file_path'];

// Support both new relative paths and old absolute paths
if ($filePath && strpos($filePath, 'customer_') === 0) {
    // New style: relative path starting with "customer_..."
    $absolutePath = $baseDir . '/' . $filePath;
} elseif ($filePath && strpos($filePath, '/uploads_lrofileupload/') !== false) {
    // Absolute path that still contains uploads_lrofileupload
    $relative = substr($filePath, strpos($filePath, '/uploads_lrofileupload/') + strlen('/uploads_lrofileupload/'));
    $absolutePath = $baseDir . '/' . $relative;
} elseif ($filePath && $filePath[0] === '/') {
    // Full absolute path (legacy) not containing uploads_lrofileupload
    $absolutePath = $filePath;
} else {
    // Fallback: assume relative to base
    $absolutePath = $baseDir . '/' . ltrim($filePath, '/');
}

if (!is_file($absolutePath) || !file_exists($absolutePath)) {
    header('HTTP/1.1 404 Not Found');
    echo 'File missing on server.';
    exit;
}

// ---- Send file -----------------------------------------------------------

$originalName = $row['original_name'] ?: $row['file_name'];
$extension    = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

$mimeTypes = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
];

$contentType = isset($mimeTypes[$extension]) ? $mimeTypes[$extension] : 'application/octet-stream';

// Clean any previous output
if (ob_get_length()) {
    ob_end_clean();
}

header('Content-Description: File Transfer');
header('Content-Type: ' . $contentType);
header('Content-Disposition: attachment; filename="' . addslashes($originalName) . '"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($absolutePath));

$fp = fopen($absolutePath, 'rb');
if ($fp !== false) {
    while (!feof($fp)) {
        echo fread($fp, 8192);
        flush();
    }
    fclose($fp);
} else {
    readfile($absolutePath);
}

exit;
