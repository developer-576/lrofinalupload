<?php
require_once __DIR__ . '/session_bootstrap.php';

// Minimal, robust logger for admin actions.
if (!function_exists('lro_log')) {
    function lro_log($action_type, $description = '', $target_type = null, $target_id = null, $group_id = null) {
        try {
            if (!class_exists('Db')) {
                // Load PS context if called from a bare script
                $root = dirname(__FILE__, 3);
                @require_once $root . '/config/config.inc.php';
                @require_once $root . '/init.php';
            }
            $db     = Db::getInstance();
            $prefix = defined('_DB_PREFIX_') ? _DB_PREFIX_ : 'ps_';
            $table  = $prefix . 'lrofileupload_action_logs';

            // Try to determine the current admin id from various contexts
            $adminId = 0;
            if (session_status() === PHP_SESSION_NONE) @session_start();
            if (!empty($_SESSION['admin_id']))               $adminId = (int)$_SESSION['admin_id'];
            elseif (!empty($_SESSION['lro_admin_id']))       $adminId = (int)$_SESSION['lro_admin_id'];
            elseif (isset($GLOBALS['cookie']->id_employee))  $adminId = (int)$GLOBALS['cookie']->id_employee;

            $sql = "INSERT INTO `{$table}`
                    (`created_at`,`admin_id`,`group_id`,`action_type`,`target_type`,`target_id`,`description`)
                    VALUES (NOW(), ".(int)$adminId.", ".($group_id===null?'NULL':(int)$group_id).",
                            '".pSQL($action_type)."',
                            ".($target_type===null?'NULL':"'".pSQL($target_type)."'").",
                            ".($target_id===null?'NULL':(int)$target_id).",
                            '".pSQL((string)$description, true)."')";
            $db->execute($sql);
        } catch (Throwable $e) {
            // Silent: logging must never block a workflow
        }
    }
}
