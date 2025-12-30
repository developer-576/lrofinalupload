<?php

class AdminLroUploadController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;

        // Table name matches your DB table (plural)
        $this->table = 'lrofileupload_files';

        // Primary key column name (singular)
        $this->identifier = 'id_lrofileupload_file';

        // Optional: ObjectModel class name if you have one
        $this->className = 'LrofileuploadFile';

        $this->lang = false;
        $this->module = Module::getInstanceByName('lrofileupload');

        parent::__construct();

        $this->fields_list = [
            'id_lrofileupload_file' => [
                'title' => $this->l('ID'),
                'align' => 'center',
                'class' => 'fixed-width-xs',
            ],
            'id_customer' => [
                'title' => $this->l('Customer ID'),
                'align' => 'center',
                'class' => 'fixed-width-xs',
            ],
            'original_name' => [
                'title' => $this->l('Original File Name'),
            ],
            'status' => [
                'title' => $this->l('Status'),
                'type' => 'select',
                'list' => [
                    'pending' => $this->l('Pending'),
                    'approved' => $this->l('Approved'),
                    'rejected' => $this->l('Rejected'),
                ],
                'filter_key' => 'a!status',
            ],
            'date_add' => [
                'title' => $this->l('Date Added'),
                'type' => 'datetime',
            ],
        ];
    }

    public function initContent()
    {
        parent::initContent();
    }
}
