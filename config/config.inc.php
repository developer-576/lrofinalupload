<?php
/** Module bootstrap for PrestaShop (robust path finder). */
declare(strict_types=1);

$root = dirname(__DIR__, 3); // from modules/lrofileupload/config -> site root
if (!is_file($root . '/config/config.inc.php')) {
    // Fallback: walk upward just in case your layout is different
    $d = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        if (is_file($d . '/config/config.inc.php')) { $root = $d; break; }
        $d = dirname($d);
    }
}

if (!is_file($root . '/config/config.inc.php')) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(500);
    exit("Could not locate PrestaShop root (config/config.inc.php).\nStart: " . __DIR__);
}

require_once $root . '/config/config.inc.php';
require_once $root . '/init.php';
