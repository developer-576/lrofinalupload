<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// PrestaShop config
require_once dirname(__DIR__, 2) . '/config/config.inc.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    file_put_contents(__DIR__ . '/logs/post_debug.log', print_r($_POST, true), FILE_APPEND);
}

try {
    $table = _DB_PREFIX_ . 'lrofileupload_admins';

    // Check if the column exists
    $col = Db::getInstance()->executeS("SHOW COLUMNS FROM `$table` LIKE 'is_master'");
    if (empty($col)) {
        // Add the column if it doesn't exist
        $result = Db::getInstance()->execute("ALTER TABLE `$table` ADD COLUMN `is_master` TINYINT(1) NOT NULL DEFAULT 0");
        if ($result) {
            echo "<b>Success:</b> Added <code>is_master</code> column to <code>$table</code>.";
        } else {
            echo "<b>Error:</b> Failed to add <code>is_master</code> column to <code>$table</code>.";
        }
    } else {
        echo "<b>Info:</b> <code>is_master</code> column already exists in <code>$table</code>.";
    }
} catch (Exception $e) {
    echo "<b>Exception:</b> " . htmlspecialchars($e->getMessage());
}
?> 