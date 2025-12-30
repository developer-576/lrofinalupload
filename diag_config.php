<?php
declare(strict_types=1);

/* PrestaShop bootstrap */
$root = realpath(__DIR__ . '/../../../');
require_once $root.'/config/config.inc.php';
require_once $root.'/init.php';

header('Content-Type: text/plain; charset=utf-8');

/* Context / versions / prefix */
$ctx = Context::getContext();
$shopId = (int)$ctx->shop->id;
$grpId  = (int)$ctx->shop->id_shop_group;

echo "PHP:        ".PHP_VERSION.PHP_EOL;
echo "PS:         "._PS_VERSION_.PHP_EOL;
echo "DB prefix:  "._DB_PREFIX_.PHP_EOL;
echo "Shop:       #{$shopId}  Group: #{$grpId}".PHP_EOL;
echo "Multishop:  ".(Configuration::get('PS_MULTISHOP_FEATURE_ACTIVE') ? 'ON' : 'OFF').PHP_EOL;

/* Clear config cache just in case */
if (method_exists('Configuration','clearConfigurationCache')) {
  Configuration::clearConfigurationCache();
}

/* Try a write -> read cycle (shop-scoped) */
$key = 'LRO_DIAG_'.date('His');
$val = 'ok-'.time();
$ok  = Configuration::updateValue($key, $val, true, $grpId ?: null, $shopId ?: null);

echo "updateValue({$key}) returned: ".($ok?'true':'false').PHP_EOL;

/* Read back shop-scoped, then global fallback */
$readShop = Configuration::get($key, null, $grpId ?: null, $shopId ?: null);
$readGlob = Configuration::get($key);

echo "read(shop):  ".var_export($readShop, true).PHP_EOL;
echo "read(global):".var_export($readGlob, true).PHP_EOL;

/* Show what’s in the DB */
$db = Db::getInstance();
$row = $db->getRow('SELECT id_configuration,name,value,id_shop,id_shop_group
                    FROM `'._DB_PREFIX_.'configuration`
                    WHERE name="'.pSQL($key).'"
                    ORDER BY id_configuration DESC');
echo "DB row: ".var_export($row, true).PHP_EOL;
