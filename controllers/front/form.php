<?php

class LrofileuploadFormModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        $id_customer = (int)$this->context->customer->id;

        // Get product groups
        $groups = Db::getInstance()->executeS('
            SELECT * FROM ' . _DB_PREFIX_ . 'lrofileupload_product_groups
            ORDER BY sort_order ASC
        ');

        foreach ($groups as &$group) {
            $group['is_purchased'] = $this->hasCustomerPurchasedProduct($id_customer, $group['id_product']);

            // Get document requirements for this group
            $requirements = Db::getInstance()->executeS('
                SELECT * FROM ' . _DB_PREFIX_ . 'lrofileupload_document_requirements 
                WHERE id_group = ' . (int)$group['id_group'] . '
                ORDER BY sort_order ASC
            ');

            foreach ($requirements as &$req) {
                $id_req = (int)$req['id_requirement'];

                $table = _DB_PREFIX_ . 'lrofileupload_files';
                $sql = "
                    SELECT * FROM $table
                    WHERE id_customer = " . (int)$id_customer . "
                    AND id_requirement = " . (int)$id_req . "
                    ORDER BY date_add DESC
                    LIMIT 1
                ";
                $file = Db::getInstance()->getRow($sql);

                $req['uploaded_file'] = $file ?: null;
            }

            $group['requirements'] = $requirements;
        }

        $this->context->smarty->assign([
            'product_groups' => $groups,
            'form_link' => $this->context->link->getModuleLink('lrofileupload', 'upload'),
            'module_dir' => __PS_BASE_URI__ . 'modules/lrofileupload/',
        ]);

        $this->setTemplate('module:lrofileupload/views/templates/front/upload.tpl');
    }

    private function hasCustomerPurchasedProduct($id_customer, $id_product)
    {
        $sql = '
            SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'orders o
            INNER JOIN ' . _DB_PREFIX_ . 'order_detail od ON o.id_order = od.id_order
            WHERE o.id_customer = ' . (int)$id_customer . '
            AND od.product_id = ' . (int)$id_product . '
            AND o.current_state IN (2, 3, 4, 5) -- Paid or delivered etc
        ';
        return (int)Db::getInstance()->getValue($sql) > 0;
    }
}
