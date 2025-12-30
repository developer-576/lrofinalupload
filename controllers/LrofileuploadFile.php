<?php
// controllers/front/UserFiles.php

class LrofileuploadUserFilesModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        if (!$this->context->customer->isLogged()) {
            Tools::redirect('index.php?controller=authentication');
            exit;
        }

        $customer_id = (int)$this->context->customer->id;

        $files = Db::getInstance()->executeS(
            'SELECT id_file, filename, status, date_add
             FROM ' . _DB_PREFIX_ . 'lrofileupload_files
             WHERE id_customer = ' . $customer_id . '
             ORDER BY date_add DESC'
        );

        foreach ($files as &$file) {
            $file['download_link'] = $this->context->link->getModuleLink(
                'lrofileupload',
                'download',
                ['file' => $file['filename']]
            );
        }

        $this->context->smarty->assign([
            'files' => $files,
        ]);

        $this->setTemplate('module:lrofileupload/views/templates/front/user_files.tpl');
    }
}
