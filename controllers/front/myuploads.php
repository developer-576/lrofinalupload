<?php

require_once dirname(__FILE__) . '/../../classes/LroFileUploadHelper.php';

class LrofileuploadMyuploadsModuleFrontController extends ModuleFrontController {}
{
    public function initContent()
    {
        parent::initContent();

        if (!$this->context->customer->isLogged()) {
            Tools::redirect('index.php?controller=authentication&back=my-uploads');
        }

        $id_customer = (int)$this->context->customer->id;
        $allUploads = LroFileUploadHelper::getAllCustomerUploads($id_customer);

        $this->context->smarty->assign([
            'allUploads' => $allUploads,
            'module_dir' => __PS_BASE_URI__ . 'modules/lrofileupload/',
            'id_customer' => $id_customer
        ]);

        $this->setTemplate('module:lrofileupload/views/templates/front/my_uploads.tpl');
    }
}
