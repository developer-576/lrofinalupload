<?php
declare(strict_types=1);

/** Bootstrap (same resilient loader used elsewhere) */
(function () {
    $dir = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        if (file_exists($dir.'/config/config.inc.php') && file_exists($dir.'/init.php')) {
            require_once $dir.'/config/config.inc.php';
            require_once $dir.'/init.php';
            return;
        }
        $dir = dirname($dir);
    }
    $root = dirname(__DIR__, 3);
    require_once $root.'/config/config.inc.php';
    require_once $root.'/init.php';
})();

/** Auth (viewers/masters) */
require_once __DIR__.'/session_bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__.'/auth.php';
require_admin_login(false);

$db     = Db::getInstance();
$prefix = _DB_PREFIX_;

function table_exists_i(string $full): bool {
    return (bool)Db::getInstance()->getValue(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA='".pSQL(_DB_NAME_)."' AND TABLE_NAME='".pSQL($full)."'"
    );
}

$tblA = $prefix.'lrofileupload_reasons';
$tblB = $prefix.'lrofileupload_rejection_reasons';

$rows = [];
if (table_exists_i($tblA)) {
    // Use executeS (no implicit LIMIT), then return rows
    $rows = $db->executeS("SELECT id_reason AS id, COALESCE(reason_text, reason) AS reason_text
                           FROM `{$tblA}`
                           WHERE COALESCE(active,1)=1
                           ORDER BY id_reason ASC") ?: [];
} elseif (table_exists_i($tblB)) {
    $rows = $db->executeS("SELECT id_reason AS id, COALESCE(reason_text, reason) AS reason_text
                           FROM `{$tblB}`
                           WHERE COALESCE(active,1)=1
                           ORDER BY id_reason ASC") ?: [];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success'=>true, 'reasons'=>$rows], JSON_UNESCAPED_UNICODE);
