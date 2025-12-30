<?php

class AdminLrofileuploadController extends ModuleAdminController
{
    public function __construct()
    {
        $this->table = 'lrofileupload_files';
        $this->className = 'LrofileuploadFile';
        $this->identifier = 'id_lrofileupload_file';
        $this->bootstrap = true;

        parent::__construct();

        $this->fields_list = [
            'id_lrofileupload_file' => ['title' => $this->l('ID')],
            'filename' => ['title' => $this->l('Filename')],
            'status' => ['title' => $this->l('Status')],
            'date_add' => ['title' => $this->l('Uploaded On')],
            'id_customer' => ['title' => $this->l('Customer ID')],
        ];

        $this->_select = 'c.firstname, c.lastname, c.email';
        $this->_join = 'LEFT JOIN ' . _DB_PREFIX_ . 'customer c ON a.id_customer = c.id_customer';

        $this->bulk_actions = [
            'approve' => [
                'text' => $this->l('Approve selected'),
                'icon' => 'icon-check',
                'confirm' => $this->l('Approve selected files?'),
            ],
            'reject' => [
                'text' => $this->l('Reject selected'),
                'icon' => 'icon-remove',
                'confirm' => $this->l('Reject selected files?'),
            ],
            'delete' => [
                'text' => $this->l('Delete selected'),
                'icon' => 'icon-trash',
                'confirm' => $this->l('Delete selected files?'),
            ],
        ];

        // ADD THIS LINE:
        $this->actions = ['view', 'approve', 'reject', 'delete'];
    }

    // Per-row Approve button
    public function displayApproveLink($token = null, $id, $name = null)
    {
        $url = $this->context->link->getAdminLink('AdminLrofileupload', true, [], [
            'approve' => 1,
            'id_lrofileupload_file' => $id,
        ]);
        return '<a href="' . $url . '" class="btn btn-success btn-xs"><i class="icon-check"></i> ' . $this->l('Approve') . '</a>';
    }

    // Per-row Reject button
    public function displayRejectLink($token = null, $id, $name = null)
    {
        $url = $this->context->link->getAdminLink('AdminLrofileupload', true, [], [
            'reject' => 1,
            'id_lrofileupload_file' => $id,
        ]);
        return '<a href="' . $url . '" class="btn btn-danger btn-xs"><i class="icon-remove"></i> ' . $this->l('Reject') . '</a>';
    }

    // Approve action (row and bulk)
    public function processApprove()
    {
        $id = (int)Tools::getValue('id_lrofileupload_file');
        if ($id) {
            $reference_number = strtoupper(uniqid('REF-'));
            Db::getInstance()->update(
                $this->table,
                [
                    'status' => 'approved',
                    'reference_number' => pSQL($reference_number),
                    'date_approved' => date('Y-m-d H:i:s'),
                ],
                $this->identifier . ' = ' . $id
            );
            $this->confirmations[] = $this->l('File approved and reference number stamped.');
        }
    }

    // Reject action (row and bulk)
    public function processReject()
    {
        $id = (int)Tools::getValue('id_lrofileupload_file');
        if ($id) {
            Db::getInstance()->update(
                $this->table,
                ['status' => 'rejected'],
                $this->identifier . ' = ' . $id
            );
            $this->confirmations[] = $this->l('File rejected.');
        }
    }

    public function processBulkApprove()
    {
        $ids = Tools::getValue($this->table . 'Box');
        if (is_array($ids)) {
            foreach ($ids as $id) {
                $reference_number = strtoupper(uniqid('REF-'));
                Db::getInstance()->update($this->table, [
                    'status' => 'approved',
                    'reference_number' => pSQL($reference_number),
                    'date_approved' => date('Y-m-d H:i:s'),
                ], $this->identifier . ' = ' . (int)$id);
            }
        }
        $this->confirmations[] = $this->l('Selected files have been approved.');
    }

    public function processBulkReject()
    {
        $ids = Tools::getValue($this->table . 'Box');
        if (is_array($ids)) {
            foreach ($ids as $id) {
                Db::getInstance()->update($this->table, [
                    'status' => 'rejected',
                ], $this->identifier . ' = ' . (int)$id);
            }
        }
        $this->confirmations[] = $this->l('Selected files have been rejected.');
    }

    public function processBulkDelete()
    {
        $ids = Tools::getValue($this->table . 'Box');
        if (is_array($ids)) {
            foreach ($ids as $id) {
                Db::getInstance()->delete($this->table, $this->identifier . ' = ' . (int)$id);
            }
        }
        $this->confirmations[] = $this->l('Selected files have been deleted.');
    }

    // Show reference number in red at the top when approved
    public function renderView()
    {
        $id = (int)Tools::getValue('id_lrofileupload_file');
        $file = new LrofileuploadFile($id);

        $html = '';
        if ($file->status == 'approved' && $file->reference_number) {
            $html .= '<div style="color: red; font-weight: bold; font-size: 1.5em; margin-bottom: 20px;">'
                   . $this->l('Reference Number: ') . htmlspecialchars($file->reference_number)
                   . '</div>';
        }

        return $html . parent::renderView();
    }
}
