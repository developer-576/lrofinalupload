<?php
require_once __DIR__ . '/session_bootstrap.php';

function lro_db() { return Db::getInstance(); }

/** SHOW TABLES via executeS (no LIMIT auto-inserted) */
function lro_scan_tables_like($like) {
    return lro_db()->executeS("SHOW TABLES LIKE '" . pSQL($like) . "'") ?: [];
}

/** columns map for a table: [colname => true] (via information_schema) */
function lro_columns($table) {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];

    $sql = "
        SELECT COLUMN_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = '" . pSQL(_DB_NAME_) . "'
          AND TABLE_NAME   = '" . pSQL($table) . "'
    ";
    $rows = lro_db()->executeS($sql) ?: [];
    $map  = [];
    foreach ($rows as $r) $map[$r['COLUMN_NAME']] = true;
    return $cache[$table] = $map;
}

/** Return the real uploads table name present in DB */
function lro_uploads_table() {
    static $table = null;
    if ($table !== null) return $table;

    $prefix = _DB_PREFIX_;
    $candidates = [
        $prefix . 'lrofileupload_uploads',
        $prefix . 'lrofileupload_uploaded_files',
        $prefix . 'lrofileupload_files',
    ];

    // Add all lro tables as fallback
    foreach (lro_scan_tables_like($prefix . 'lrofileupload\_%') as $r) {
        $name = array_values($r)[0];
        if (!in_array($name, $candidates, true)) $candidates[] = $name;
    }

    $wanted = ['file_name','status','id_customer'];
    $best = null; $bestScore = -1;

    foreach ($candidates as $name) {
        $existsRows = lro_db()->executeS("
            SELECT TABLE_NAME
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = '" . pSQL(_DB_NAME_) . "'
              AND TABLE_NAME   = '" . pSQL($name) . "'
        ");
        if (!$existsRows) continue;

        $cols  = lro_columns($name);
        $score = 0;
        foreach ($wanted as $w) if (isset($cols[$w])) $score++;
        if ($score > $bestScore) { $bestScore = $score; $best = $name; }
    }

    if (!$best) throw new Exception('Uploads table not found in DB.');
    return $table = $best;
}

/** Detect primary key column of uploads table (via information_schema) */
function lro_uploads_pk() {
    static $pk = null;
    if ($pk !== null) return $pk;

    $table = lro_uploads_table();

    foreach (['file_id','id','id_file','upload_id','id_upload'] as $col) {
        if (isset(lro_columns($table)[$col])) { $pk = $col; return $pk; }
    }

    $rows = lro_db()->executeS("
        SELECT k.COLUMN_NAME
        FROM information_schema.TABLE_CONSTRAINTS t
        JOIN information_schema.KEY_COLUMN_USAGE k
          ON k.CONSTRAINT_NAME = t.CONSTRAINT_NAME
         AND k.TABLE_SCHEMA    = t.TABLE_SCHEMA
         AND k.TABLE_NAME      = t.TABLE_NAME
        WHERE t.TABLE_SCHEMA   = '" . pSQL(_DB_NAME_) . "'
          AND t.TABLE_NAME     = '" . pSQL($table) . "'
          AND t.CONSTRAINT_TYPE = 'PRIMARY KEY'
        ORDER BY k.ORDINAL_POSITION ASC
    ");
    if ($rows && !empty($rows[0]['COLUMN_NAME'])) return $pk = $rows[0]['COLUMN_NAME'];

    throw new Exception('Primary key not found for uploads table.');
}

/** Path helpers */
function lro_build_relpath($id_customer, $id_group, $filename) {
    return 'customer_' . (int)$id_customer . '/group_' . (int)$id_group . '/' . lro_basename_safe($filename);
}
function lro_basename_safe($name) {
    $name = str_replace(['\\','/'], '/', (string)$name);
    return basename($name);
}

/** Debug (optional) */
function lro_debug_table_info() {
    return [
        'table' => lro_uploads_table(),
        'pk'    => lro_uploads_pk(),
        'cols'  => array_keys(lro_columns(lro_uploads_table())),
    ];
}
