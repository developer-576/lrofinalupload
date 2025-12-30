<?php
ini_set('display_errors',1); error_reporting(E_ALL);
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/auth.php'; require_admin_login();

if (!defined('_PS_VERSION_')) {
  $psRoot = realpath(__DIR__ . '/../../../');
  require_once $psRoot . '/config/config.inc.php';
  require_once $psRoot . '/init.php';
}

$db = Db::getInstance();
$table = _DB_PREFIX_ . 'lrofileupload_uploads';
header('Content-Type: application/json; charset=utf-8');

$out = ['table'=>$table,'keys'=>[],'columns'=>[]];
try { $out['keys'] = $db->executeS("SHOW KEYS FROM {$table}") ?: []; } catch (Throwable $e) { $out['keys_error']=$e->getMessage(); }
try { $out['columns'] = $db->executeS("SHOW COLUMNS FROM {$table}") ?: []; } catch (Throwable $e) { $out['columns_error']=$e->getMessage(); }

echo json_encode($out, JSON_PRETTY_PRINT);
