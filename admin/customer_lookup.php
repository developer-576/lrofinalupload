<?php
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';

if (function_exists('lro_require_admin')) { lro_require_admin(false); }
elseif (function_exists('require_admin_login')) { require_admin_login(false); }
else { if (session_status() !== PHP_SESSION_ACTIVE) session_start(); if (empty($_SESSION['admin_id'])) { http_response_code(403); exit; } }

$prefix = _DB_PREFIX_;
$db = Db::getInstance();
$q  = Tools::substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$rows = [];

if ($q !== '') {
  $like = '%'.pSQL($q).'%';
  $rows = $db->executeS("
    SELECT c.id_customer, c.firstname, c.lastname, c.email,
           (SELECT o.reference FROM {$prefix}orders o WHERE o.id_customer=c.id_customer ORDER BY o.date_add DESC LIMIT 1) AS reference
    FROM {$prefix}customer c
    WHERE CONCAT_WS(' ', c.firstname, c.lastname) LIKE '{$like}'
       OR c.email LIKE '{$like}'
       OR EXISTS (SELECT 1 FROM {$prefix}orders o WHERE o.id_customer=c.id_customer AND o.reference LIKE '{$like}')
    ORDER BY c.firstname, c.lastname
    LIMIT 50
  ") ?: [];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['rows'=>$rows], JSON_UNESCAPED_UNICODE);
