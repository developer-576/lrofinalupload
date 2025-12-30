<?php

class LrofileuploadDocumentsModuleFrontController extends ModuleFrontController {}
{
    public function initContent()
    {
        parent::initContent();

        $customer_id = (int)$this->context->customer->id;

        // --- Handle file deletion ---
        if (Tools::isSubmit('delete_file_id')) {
            $id = (int)Tools::getValue('delete_file_id');
            $file = Db::getInstance()->getRow('SELECT * FROM ' . _DB_PREFIX_ . 'lrofileupload_files WHERE id_lrofileupload_file = ' . $id . ' AND id_customer = ' . $customer_id);
            if ($file) {
                // Delete file from filesystem
                $filepath = _PS_UPLOAD_DIR_ . 'lrofileupload/' . $file['filename'];
                if (file_exists($filepath)) {
                    @unlink($filepath);
                }
                // Delete from DB
                Db::getInstance()->delete('lrofileupload_files', 'id_lrofileupload_file = ' . $id);
            }
            Tools::redirect($_SERVER['REQUEST_URI']);
        }

        // --- Fetch uploaded files for this customer ---
        $files = Db::getInstance()->executeS('SELECT * FROM ' . _DB_PREFIX_ . 'lrofileupload_files WHERE id_customer = ' . $customer_id . ' ORDER BY date_add DESC');

        foreach ($files as &$file) {
            $file['url'] = __PS_BASE_URI__ . 'upload/lrofileupload/' . $file['filename'];
            $file['download_url'] = ($file['status'] == 'approved')
                ? $this->context->link->getModuleLink('lrofileupload', 'download', ['id' => $file['id_lrofileupload_file']])
                : '';
            $file['size'] = file_exists(_PS_UPLOAD_DIR_ . 'lrofileupload/' . $file['filename'])
                ? filesize(_PS_UPLOAD_DIR_ . 'lrofileupload/' . $file['filename'])
                : 0;
            // If you have expiration date or description in your DB, use them; else set to empty
            if (!isset($file['expiration_date'])) $file['expiration_date'] = '';
            if (!isset($file['description'])) $file['description'] = '';
        }
        unset($file);

        $this->context->smarty->assign([
            'uploaded_files' => $files,
        ]);

        $this->setTemplate('module:lrofileupload/views/templates/front/documents.tpl');
    }
}