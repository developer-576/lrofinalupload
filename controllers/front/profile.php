<?php

class LrofileuploadProfileModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function initContent()
    {
        parent::initContent();

        // Only logged-in customers
        if (!$this->context->customer->isLogged()) {
            Tools::redirect('index.php?controller=authentication&back=module-lrofileupload-profile');
        }

        $errors = [];
        $success = false;

        // Handle file upload
        if (Tools::isSubmit('submitUpload')) {
            $document_type = (int)Tools::getValue('document_type');
            if (!$document_type) {
                $errors[] = $this->module->l('Please select a document type.');
            }
            if (empty($_FILES['upload_files']['name'][0])) {
                $errors[] = $this->module->l('Please select at least one file.');
            }

            if (empty($errors)) {
                $allowedExtensions = ['pdf', 'jpeg', 'jpg'];
                $allowedMimes = ['application/pdf', 'image/jpeg'];
                $maxSize = 200 * 1024 * 1024; // 200 MB
                $uploadDir = _PS_UPLOAD_DIR_ . 'lrofileupload/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                foreach ($_FILES['upload_files']['tmp_name'] as $key => $tmp_name) {
                    if ($_FILES['upload_files']['error'][$key] !== UPLOAD_ERR_OK) {
                        continue;
                    }
                    $file = [
                        'name' => $_FILES['upload_files']['name'][$key],
                        'type' => $_FILES['upload_files']['type'][$key],
                        'tmp_name' => $tmp_name,
                        'error' => $_FILES['upload_files']['error'][$key],
                        'size' => $_FILES['upload_files']['size'][$key]
                    ];
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowedExtensions) || !in_array($file['type'], $allowedMimes)) {
                        $errors[] = sprintf($this->module->l('File %s: Only PDF and JPEG files are allowed.'), $file['name']);
                        continue;
                    }
                    if ($file['size'] > $maxSize) {
                        $errors[] = sprintf($this->module->l('File %s: Size exceeds the 200 MB limit.'), $file['name']);
                        continue;
                    }
                    $newName = uniqid('', true) . '.' . $ext;
                    $destination = $uploadDir . $newName;
                    if (!move_uploaded_file($file['tmp_name'], $destination)) {
                        $errors[] = sprintf($this->module->l('Failed to move file %s.'), $file['name']);
                        continue;
                    }
                    $insert = [
                        'id_customer'    => (int)$this->context->customer->id,
                        'id_product'     => 0, // or set if you want to link to a product
                        'filename'       => pSQL($newName),
                        'original_name'  => pSQL($file['name']),
                        'status'         => 'pending',
                        'date_add'       => date('Y-m-d H:i:s'),
                        'reason'         => '',
                        'id_reason'      => $document_type,
                    ];
                    Db::getInstance()->insert('lrofileupload_files', $insert);
                    $success = true;
                }
            }
        }

        // Get document types (reasons)
        $document_types = Db::getInstance()->executeS('SELECT * FROM '._DB_PREFIX_.'lrofileupload_reasons');

        // Get uploaded files for this customer
        $uploaded_files = Db::getInstance()->executeS('
            SELECT f.*, r.reason_text
            FROM '._DB_PREFIX_.'lrofileupload_files f
            LEFT JOIN '._DB_PREFIX_.'lrofileupload_reasons r ON f.id_reason = r.id_reason
            WHERE f.id_customer = '.(int)$this->context->customer->id.'
            ORDER BY f.date_add DESC
        ');

        // Add download link for each file
        foreach ($uploaded_files as &$file) {
            $file['download_link'] = __PS_BASE_URI__ . 'upload/lrofileupload/' . $file['filename'];
        }

        $this->context->smarty->assign([
            'errors'         => $errors,
            'success'        => $success,
            'document_types' => $document_types,
            'uploaded_files' => $uploaded_files,
        ]);

        $this->setTemplate('module:lrofileupload/views/templates/front/profile.tpl');
    }
} 