<?php

require_once dirname(__FILE__) . '/../../classes/LroFileUploadHelper.php';

class LrofileuploadRejecteduploadsModuleFrontController extends ModuleFrontController {}
{
    public function initContent()
    {
        parent::initContent();

        if (!$this->context->customer->isLogged()) {
            Tools::redirect('index.php?controller=authentication&back=rejected-uploads');
        }

        $id_customer = (int)$this->context->customer->id;
        $rejectedUploads = LroFileUploadHelper::getRejectedUploads($id_customer);

        $this->context->smarty->assign([
            'rejectedUploads' => $rejectedUploads,
            'module_dir' => __PS_BASE_URI__ . 'modules/lrofileupload/',
            'id_customer' => $id_customer
        ]);

        $this->setTemplate('module:lrofileupload/views/templates/front/rejected_uploads.tpl');
    }
}
