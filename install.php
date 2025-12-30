<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

function install_lrofileupload()
{
    try {
        $sql_file = dirname(__FILE__) . '/sql/install.sql';
        if (!file_exists($sql_file)) {
            PrestaShopLogger::addLog('Lrofileupload installation error: SQL file not found at ' . $sql_file, 3);
            return false;
        }

        $sql = file_get_contents($sql_file);
        if (!$sql) {
            PrestaShopLogger::addLog('Lrofileupload installation error: Could not read SQL file', 3);
            return false;
        }

        // Replace prefix
        $sql = str_replace('{PREFIX}', _DB_PREFIX_, $sql);

        // Split into individual queries
        $queries = array_filter(array_map('trim', explode(';', $sql)));

        // Execute each query
        foreach ($queries as $query) {
            if (!empty($query)) {
                try {
                    $result = Db::getInstance()->execute($query);
                    if (!$result) {
                        $error = Db::getInstance()->getMsgError();
                        PrestaShopLogger::addLog('Lrofileupload installation error: ' . $error . ' in query: ' . $query, 3);
                        return false;
                    }
                } catch (Exception $e) {
                    PrestaShopLogger::addLog('Lrofileupload installation error: ' . $e->getMessage() . ' in query: ' . $query, 3);
                    return false;
                }
            }
        }

        // Verify tables were created
        $tables = [
            'lrofileupload_files',
            'lrofileupload_admins',
            'lrofileupload_product_groups',
            'lrofileupload_document_requirements'
        ];

        foreach ($tables as $table) {
            $table_exists = Db::getInstance()->executeS('SHOW TABLES LIKE \'' . _DB_PREFIX_ . $table . '\'');
            if (empty($table_exists)) {
                PrestaShopLogger::addLog('Lrofileupload installation error: Table ' . $table . ' was not created', 3);
                return false;
            }
        }

        return true;
    } catch (Exception $e) {
        PrestaShopLogger::addLog('Lrofileupload installation error: ' . $e->getMessage(), 3);
        return false;
    }
}

function uninstall_lrofileupload()
{
    try {
        $tables = [
            'lrofileupload_files',
            'lrofileupload_admins',
            'lrofileupload_product_groups',
            'lrofileupload_document_requirements'
        ];

        foreach ($tables as $table) {
            try {
                $result = Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $table . '`');
                if (!$result) {
                    $error = Db::getInstance()->getMsgError();
                    PrestaShopLogger::addLog('Lrofileupload uninstallation error: ' . $error . ' when dropping table ' . $table, 3);
                    return false;
                }
            } catch (Exception $e) {
                PrestaShopLogger::addLog('Lrofileupload uninstallation error: ' . $e->getMessage() . ' when dropping table ' . $table, 3);
                return false;
            }
        }

        return true;
    } catch (Exception $e) {
        PrestaShopLogger::addLog('Lrofileupload uninstallation error: ' . $e->getMessage(), 3);
        return false;
    }
} 