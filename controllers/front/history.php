<?php

class LrofileuploadHistoryModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        // Must be logged in
        if (!$this->context->customer->isLogged()) {
            Tools::redirect('index.php?controller=authentication');
        }

        $customerId = (int) $this->context->customer->id;

        // Simple one-line SQL: all uploads for this customer
        $sql = 'SELECT id_upload, file_path, status, date_uploaded
                FROM psfc_lrofileupload_uploads
                WHERE id_customer = ' . $customerId . '
                ORDER BY date_uploaded DESC';

        $uploads = Db::getInstance()->executeS($sql);

        if (!is_array($uploads)) {
            $uploads = [];
        }

        // Short display name for the table
        foreach ($uploads as &$u) {
            $u['short_name'] = basename($u['file_path']);
        }
        unset($u);

        $this->context->smarty->assign([
            'uploads'       => $uploads,
            'count_uploads' => count($uploads),
        ]);

        $this->setTemplate('module:lrofileupload/views/templates/front/history.tpl');
    }


    public function postProcess()
    {
        // Only react if a download is requested
        if (!Tools::getIsset('download')) {
            return;
        }

        // Must be logged in
        if (!$this->context->customer->isLogged()) {
            Tools::redirect('index.php?controller=authentication');
        }

        $fileId     = (int) Tools::getValue('download');
        $customerId = (int) $this->context->customer->id;

        // Fetch this upload for this customer (NO LIMIT)
        $sql = 'SELECT file_path
                FROM psfc_lrofileupload_uploads
                WHERE id_upload = ' . $fileId . '
                  AND id_customer = ' . $customerId;

        $file = Db::getInstance()->getRow($sql);

        if (!$file || empty($file['file_path'])) {
            die('File not found or unauthorized.');
        }

        $path = $file['file_path'];

        if (!file_exists($path)) {
            die('File missing on server: ' . htmlspecialchars($path));
        }

        // Clean output buffer before sending file
        if (ob_get_level()) {
            ob_end_clean();
        }

        // Force file download
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));

        readfile($path);
        exit;
    }
}
