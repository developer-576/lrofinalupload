<?php
// modules/lrofileupload/admin/api_reasons.php
declare(strict_types=1);

require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/auth.php';
require_admin_login();

header('Content-Type: application/json; charset=utf-8');
// stop any caches from serving stale lists
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$prefix = defined('_DB_PREFIX_') ? _DB_PREFIX_ : 'psfc_';

try {
    global $pdo;
    $stmt = $pdo->query("
        SELECT id_reason AS id, reason_text
        FROM {$prefix}lrofileupload_reasons
        ORDER BY id_reason DESC
    ");
    echo json_encode(['success' => true, 'reasons' => $stmt->fetchAll()]);
} catch (Throwable $e) {
    error_log('[api_reasons] '.$e->getMessage());
    echo json_encode(['success' => false, 'error' => 'server_error']);
}
