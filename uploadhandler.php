<?php
/**
 * Secure upload handler for LRO File Upload module
 * Stores files under:  /home/.../uploads_lrofileupload/customer_{id}/g{id_group}/r{id_requirement}/
 * and saves the relative path in psfc_lrofileupload_uploads.file_path
 */

use PrestaShop\PrestaShop\Adapter\Entity\Db;
use PrestaShop\PrestaShop\Adapter\Entity\Context;

require dirname(__FILE__) . '/../../config/config.inc.php';
require dirname(__FILE__) . '/../../init.php';

header('Content-Type: application/json');

// Simple helper for JSON responses
function lro_json_response($success, $message, $extra = [])
{
    echo json_encode(array_merge([
        'success' => (bool) $success,
        'message' => $message,
    ], $extra));
    exit;
}

$context = Context::getContext();
$customer = $context->customer;

if (!$customer || !$customer->isLogged()) {
    lro_json_response(false, 'You must be logged in to upload documents.');
}

// ---- INPUTS --------------------------------------------------------------

$id_customer    = (int) $customer->id;
$id_order       = (int) Tools::getValue('id_order', 0);
$id_group       = (int) Tools::getValue('id_group', 0);
$id_requirement = (int) Tools::getValue('id_requirement', 0);
$requirement_name = trim(Tools::getValue('requirement_name', ''));

// Basic checks
if ($id_group <= 0 || $id_requirement <= 0) {
    lro_json_response(false, 'Invalid document group or requirement.');
}

if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    lro_json_response(false, 'No file received.');
}

$file = $_FILES['file'];

// ---- CONFIG: base + relative paths ---------------------------------------

// PrestaShop root is usually /home/USER/public_html
// uploads directory is /home/USER/uploads_lrofileupload
$psRoot  = _PS_ROOT_DIR_; // e.g. /home/mfjprqzu/public_html
$baseDir = rtrim(dirname($psRoot), '/') . '/uploads_lrofileupload';

$relativeDir = sprintf(
    'customer_%d/g%d/r%d',
    $id_customer,
    $id_group,
    $id_requirement
);

$absoluteDir = $baseDir . '/' . $relativeDir;

// ---- VALIDATION & DIRECTORY CREATION -------------------------------------

// Very light mime/extension validation (adjust if you like)
$allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
$originalName = $file['name'];
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

if (!in_array($extension, $allowedExtensions)) {
    lro_json_response(false, 'Invalid file type. Allowed: PDF, JPG, PNG.');
}

if (!is_dir($absoluteDir)) {
    if (!@mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
        lro_json_response(false, 'Could not create upload directory.');
    }
}

// Generate a safe storage name
$sanitizedBase = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
if ($sanitizedBase === '') {
    $sanitizedBase = 'document';
}
$storedName = $sanitizedBase . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $extension;

$absolutePath = $absoluteDir . '/' . $storedName;
$relativePath = $relativeDir . '/' . $storedName;

// ---- MOVE FILE -----------------------------------------------------------

if (!@move_uploaded_file($file['tmp_name'], $absolutePath)) {
    lro_json_response(false, 'Failed to move uploaded file.');
}

// Ensure readable
@chmod($absolutePath, 0644);

// ---- INSERT DB ROW -------------------------------------------------------

$now = date('Y-m-d H:i:s');

$data = [
    'id_customer'      => $id_customer,
    'id_order'         => $id_order,
    'id_group'         => $id_group,
    'id_requirement'   => $id_requirement,
    'is_active'        => 1,
    'file_name'        => pSQL($storedName),
    'file_path'        => pSQL($relativePath),  // RELATIVE path only
    'original_name'    => pSQL($originalName),
    'status'           => pSQL('pending'),
    'requirement_name' => pSQL($requirement_name),
    'date_uploaded'    => pSQL($now),
];

if (!Db::getInstance()->insert('lrofileupload_uploads', $data)) {
    // Clean up file on failure
    if (file_exists($absolutePath)) {
        @unlink($absolutePath);
    }
    lro_json_response(false, 'Database error while saving upload.');
}

$id_upload = (int) Db::getInstance()->Insert_ID();

// ---- RESPONSE ------------------------------------------------------------

lro_json_response(true, 'File uploaded successfully.', [
    'id_upload'      => $id_upload,
    'file_name'      => $storedName,
    'original_name'  => $originalName,
    'status'         => 'pending',
    'relative_path'  => $relativePath,
]);
