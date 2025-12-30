<?php
// modules/lrofileupload/tools/unlock_from_order.php
require dirname(__DIR__, 2).'/config/config.inc.php';
require dirname(__DIR__, 2).'/init.php';

header('Content-Type: application/json; charset=utf-8');

$idOrder = (int)Tools::getValue('order');
$secret  = Tools::getValue('k'); // optional protection
// simple protection (change the string!):
if ($secret !== 'CHANGE_ME') { echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }

if ($idOrder <= 0) { echo json_encode(['ok'=>false,'error'=>'missing order']); exit; }

$order = new Order($idOrder);
if (!Validate::isLoadedObject($order)) {
    echo json_encode(['ok'=>false,'error'=>'order not found']); exit;
}

// Optional: enforce paid
if (!$order->hasBeenPaid()) {
    echo json_encode(['ok'=>false,'error'=>'order not paid']); exit;
}

$db = Db::getInstance();
$P  = _DB_PREFIX_;
$idCustomer = (int)$order->id_customer;

$sql = "
    INSERT INTO {$P}lrofileupload_manual_unlocks (id_customer, id_group)
    SELECT DISTINCT {$idCustomer}, pg.id_group
    FROM {$P}order_detail od
    JOIN {$P}lrofileupload_product_groups pg
          ON pg.id_product = od.product_id
    JOIN {$P}lrofileupload_groups g
          ON g.id_group = pg.id_group AND g.active = 1
    LEFT JOIN {$P}lrofileupload_manual_unlocks mu
          ON mu.id_customer = {$idCustomer} AND mu.id_group = pg.id_group
    WHERE od.id_order = {$idOrder}
      AND mu.id_customer IS NULL
";
$ok = $db->execute($sql);

echo json_encode(['ok'=>(bool)$ok, 'order'=>$idOrder, 'customer'=>$idCustomer]);
