<?php
/**
 * modules/lrofileupload/lib/gate.php
 * Open if: manual unlock OR purchased mapped product.
 * Mapping source:
 *   A) Table  ps*_lrofileupload_group_products (id_group, id_product)  [optional]
 *   B) Config LROFU_GROUP_PRODUCT_MAP   JSON {"55":[32,31],"60":[30]}
 */
declare(strict_types=1);

if (!defined('_PS_VERSION_')) { exit; }

/* ---------- tiny per-request cache ---------- */
if (!isset($GLOBALS['LRO_GATE_CACHE'])) {
    $GLOBALS['LRO_GATE_CACHE'] = [
        'exists_checked' => false,
        'table_exists'   => null,
        'open'           => [],          // "cid:gid" => bool
        'map_tbl_exists' => null,
        'map_group_ids'  => [],          // gid => [product ids]
    ];
}

/* ---------- manual unlocks ---------- */

function lro_unlock_table_exists(): bool {
    $c =& $GLOBALS['LRO_GATE_CACHE'];
    if ($c['exists_checked']) return (bool)$c['table_exists'];

    $db  = Db::getInstance();
    $tbl = _DB_PREFIX_.'lrofileupload_manual_unlocks';
    $exists = (bool)$db->getValue(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA='".pSQL(_DB_NAME_)."'
           AND TABLE_NAME='".pSQL($tbl)."'"
    );

    $c['exists_checked'] = true;
    $c['table_exists']   = $exists;
    return $exists;
}

/** strict group-specific manual unlock check */
function lro_is_group_open_manual(int $idCustomer, int $idGroup): bool {
    if ($idCustomer<=0 || $idGroup<=0) return false;
    if (!lro_unlock_table_exists()) return false;

    $db = Db::getInstance(); $P = _DB_PREFIX_;
    return (bool)$db->getValue("
        SELECT 1
          FROM `{$P}lrofileupload_manual_unlocks`
         WHERE id_customer = ".(int)$idCustomer."
           AND id_group    = ".(int)$idGroup."
           AND is_active   = 1
           AND (expires_at IS NULL OR expires_at > NOW())
    ");
}

/* ---------- group↔product mapping ---------- */

function lro_map_table_exists(): bool {
    $c =& $GLOBALS['LRO_GATE_CACHE'];
    if ($c['map_tbl_exists'] !== null) return (bool)$c['map_tbl_exists'];

    $db = Db::getInstance(); $tbl = _DB_PREFIX_.'lrofileupload_group_products';
    $exists = (bool)$db->getValue(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA='".pSQL(_DB_NAME_)."'
           AND TABLE_NAME='".pSQL($tbl)."'"
    );
    $c['map_tbl_exists'] = $exists;
    return $exists;
}

/**
 * Return product IDs mapped to a group.
 * Source 1: table ps*_lrofileupload_group_products (id_group,id_product)
 * Source 2: Configuration::get('LROFU_GROUP_PRODUCT_MAP') JSON like {"55":[101,102],"60":[203]}
 */
function lro_group_product_ids(int $idGroup): array {
    $c =& $GLOBALS['LRO_GATE_CACHE'];
    if (isset($c['map_group_ids'][$idGroup])) return $c['map_group_ids'][$idGroup];

    $ids = [];

    if (lro_map_table_exists()) {
        $db = Db::getInstance(); $P = _DB_PREFIX_;
        $rows = $db->executeS("
            SELECT DISTINCT id_product
              FROM `{$P}lrofileupload_group_products`
             WHERE id_group = ".(int)$idGroup
        ) ?: [];
        foreach ($rows as $r) {
            $pid = (int)($r['id_product'] ?? 0);
            if ($pid>0) $ids[] = $pid;
        }
    } elseif (class_exists('Configuration')) {
        $json = (string)Configuration::get('LROFU_GROUP_PRODUCT_MAP');
        if ($json) {
            $map = json_decode($json, true);
            // JSON keys might be strings; compare numerically
            $key = (string)$idGroup;
            if (is_array($map) && isset($map[$key]) && is_array($map[$key])) {
                foreach ($map[$key] as $pid) {
                    $pid = (int)$pid;
                    if ($pid>0) $ids[] = $pid;
                }
            }
        }
    }

    // normalise: distinct positive ints
    $ids = array_values(array_unique(array_filter($ids, function($v){ return (int)$v > 0; })));
    $c['map_group_ids'][$idGroup] = $ids;
    return $ids;
}

/* ---------- purchase check (HARDENED) ---------- */

/**
 * True if customer has any order that reached a 'paid' state and
 * contains one of the given product IDs. Handles empty/invalid id lists safely.
 */
function lro_customer_bought_any(int $idCustomer, array $productIds): bool {
    if ($idCustomer <= 0) return false;

    // keep only distinct positive integers
    $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), function($v){ return $v > 0; })));

    // no valid ids => definitely not purchased (avoid IN ())
    if (!$ids) return false;

    $db  = Db::getInstance();
    $P   = _DB_PREFIX_;
    $in  = implode(',', $ids);

    $sql = "
        SELECT 1
          FROM `{$P}orders` o
          JOIN `{$P}order_detail`  od ON od.id_order = o.id_order
          JOIN `{$P}order_history` oh ON oh.id_order = o.id_order
          JOIN `{$P}order_state`   os ON os.id_order_state = oh.id_order_state
         WHERE o.id_customer = ".(int)$idCustomer."
           AND od.product_id IN ($in)
           AND os.paid = 1
    "; // Db::getValue adds LIMIT 1 automatically

    return (bool)$db->getValue($sql);
}

/** true if group is open due to a paid purchase of a mapped product */
function lro_is_group_open_by_purchase(int $idCustomer, int $idGroup): bool {
    $pids = lro_group_product_ids($idGroup);
    if (!$pids) return false;            // avoid IN ()
    return lro_customer_bought_any($idCustomer, $pids);
}

/* ---------- unified gate ---------- */

function lro_is_group_open(int $idCustomer, int $idGroup): bool {
    $cache =& $GLOBALS['LRO_GATE_CACHE'];
    if ($idCustomer<=0 || $idGroup<=0) return false;

    $key = $idCustomer.':'.$idGroup;
    if (array_key_exists($key, $cache['open'])) {
        return (bool)$cache['open'][$key];
    }

    $open = lro_is_group_open_manual($idCustomer, $idGroup)
         || lro_is_group_open_by_purchase($idCustomer, $idGroup);

    $cache['open'][$key] = $open;
    return $open;
}

/**
 * Optional helper: list of open groups for a customer
 * (manual + purchase-based).
 */
function lro_open_groups_for_customer(int $idCustomer, array $candidateGroupIds = []): array {
    if ($idCustomer<=0) return [];
    $open = [];

    // manual
    if (lro_unlock_table_exists()) {
        $db = Db::getInstance(); $P = _DB_PREFIX_;
        $rows = $db->executeS("
            SELECT id_group
              FROM `{$P}lrofileupload_manual_unlocks`
             WHERE id_customer = ".(int)$idCustomer."
               AND is_active   = 1
               AND (expires_at IS NULL OR expires_at > NOW())
        ") ?: [];
        foreach ($rows as $r) { $g=(int)($r['id_group']??0); if ($g>0) $open[$g]=true; }
    }

    // candidates (use JSON keys if not provided and cache empty)
    if (!$candidateGroupIds) {
        $keysFromCache = array_keys($GLOBALS['LRO_GATE_CACHE']['map_group_ids'] ?? []);
        if ($keysFromCache) {
            $candidateGroupIds = $keysFromCache;
        } elseif (class_exists('Configuration')) {
            $json = (string)Configuration::get('LROFU_GROUP_PRODUCT_MAP');
            if ($json) {
                $map = json_decode($json, true);
                if (is_array($map)) $candidateGroupIds = array_map('intval', array_keys($map));
            }
        }
    }

    foreach ($candidateGroupIds as $gid) {
        $gid = (int)$gid;
        if ($gid>0 && !isset($open[$gid]) && lro_is_group_open_by_purchase($idCustomer, $gid)) {
            $open[$gid] = true;
        }
    }
    return array_keys($open);
}
