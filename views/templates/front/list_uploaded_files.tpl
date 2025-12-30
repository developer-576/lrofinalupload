<?php
class LrofileUploadUploadModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();
        $customer = $this->context->customer;
        if (!$customer->isLogged()) {
            Tools::redirect('index.php?controller=authentication');
        }

        $id_customer = (int)$customer->id;
        $uploads = Db::getInstance()->executeS('
            SELECT * FROM `'._DB_PREFIX_.'lrofileupload_files`
            WHERE id_customer = '.(int)$id_customer.'
            ORDER BY date_uploaded DESC
        ');

        foreach ($uploads as &$file) {
            $file['status_label'] = ucfirst($file['status']);
            $file['downloadable'] = $file['status'] === 'approved';
            $file['file_url'] = $this->context->link->getModuleLink('lrofileupload', 'download', ['id_file' => $file['id_file']]);
        }

        $this->context->smarty->assign([
            'uploads' => $uploads,
            'upload_action' => $this->context->link->getModuleLink('lrofileupload', 'uploadhandler')
        ]);

        $this->setTemplate('module:lrofileupload/views/templates/front/list_uploaded_files.tpl');
    }
}
