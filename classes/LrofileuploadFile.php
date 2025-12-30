<?php
class LrofileuploadFile
{
    public static function approve($id)
    {
        $now = date('Y-m-d H:i:s');
        $sql = 'UPDATE `' . _DB_PREFIX_ . 'lrofileupload_files`
                SET `status` = "approved",
                    `date_approved` = "' . pSQL($now) . '",
                    `reason` = NULL
                WHERE `id_lrofileupload_file` = ' . (int)$id;

        $result = Db::getInstance()->execute($sql);
        if (!$result) {
            return 'SQL Error: ' . Db::getInstance()->getMsgError();
        }
        return true;
    }

    public static function getAll()
    {
        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'lrofileupload_files` ORDER BY `date_add` DESC';
        return Db::getInstance()->executeS($sql);
    }

    public static function deleteById($id)
    {
        $sql = 'DELETE FROM `' . _DB_PREFIX_ . 'lrofileupload_files` WHERE `id_lrofileupload_file` = ' . (int)$id;
        return Db::getInstance()->execute($sql);
    }
}