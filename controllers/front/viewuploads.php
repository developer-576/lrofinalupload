<?php
class LrofileuploadViewuploadsModuleFrontController extends ModuleFrontController {}
{
    public function initContent()
    {
        parent::initContent();

        $id_customer = (int)Tools::getValue('id_customer');
        if (!$id_customer) {
            die('Missing customer ID');
        }

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
        ]);

        $this->setTemplate('module:lrofileupload/views/templates/front/public_uploads.tpl');
    }
}
