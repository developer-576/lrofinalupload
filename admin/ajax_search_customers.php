<?php
declare(strict_types=1);
$psRoot = dirname(__FILE__, 4);
require_once $psRoot.'/config/config.inc.php';
require_once $psRoot.'/init.php';
require_once __DIR__.'/auth.php';
require_admin_login();
require_cap('can_view_credential_card'); // this aligns with your earlier endpoints
header('Content-Type: application/json; charset=UTF-8');
// ... keep the rest of your existing logic below ...
