<?php
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';
if (function_exists('lro_require_admin')) { lro_require_admin(false); }
header('Location: reasons.php', true, 302);
exit;
