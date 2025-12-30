<?php

class LroFileUploadHelper
{
    public static function getCustomerAssignedGroups($id_customer)
    {
        $db = Db::getInstance();
        $prefix = _DB_PREFIX_;

        $groups = [];

        // 1. Fetch groups via MANUAL UNLOCKS
        $sql = "
            SELECT g.id_group, g.group_name, g.description
            FROM {$prefix}lrofileupload_product_groups g
            JOIN {$prefix}lrofileupload_manual_unlocks mu ON g.id_group = mu.id_group
            WHERE mu.id_customer = " . (int)$id_customer . "
            ORDER BY g.sort_order ASC
        ";
        $manualGroups = $db->executeS($sql);

        foreach ($manualGroups as $group) {
            $groups[$group['id_group']] = $group;
        }

        // 2. Fetch groups via PURCHASED PRODUCTS
        $sql = "
            SELECT DISTINCT g.id_group, g.group_name, g.description
            FROM {$prefix}orders o
            JOIN {$prefix}order_detail od ON o.id_order = od.id_order
            JOIN {$prefix}lrofileupload_product_group_links l ON od.product_id = l.id_product
            JOIN {$prefix}lrofileupload_product_groups g ON g.id_group = l.id_group
            WHERE o.id_customer = " . (int)$id_customer . " AND o.current_state IN (4, 5)
            ORDER BY g.sort_order ASC
        ";
        $purchaseGroups = $db->executeS($sql);

        foreach ($purchaseGroups as $group) {
            $groups[$group['id_group']] = $group;
        }

        // 3. Fetch requirements + uploads per group
        foreach ($groups as &$group) {
            $sqlReqs = "
                SELECT r.id_requirement, r.requirement_name, r.required, r.type as file_type, 
                       u.status, u.rejection_reason, u.file_name as file_path
                FROM {$prefix}lrofileupload_group_requirements r
                LEFT JOIN {$prefix}lrofileupload_uploads u 
                    ON u.id_requirement = r.id_requirement AND u.id_customer = " . (int)$id_customer . "
                WHERE r.id_group = " . (int)$group['id_group'] . "
                ORDER BY r.sort_order ASC
            ";
            $group['requirements'] = $db->executeS($sqlReqs);
        }

        return array_values($groups);
    }

    public static function getAllCustomerUploads($id_customer)
    {
        $db = Db::getInstance();
        $prefix = _DB_PREFIX_;

        $sql = "
            SELECT g.group_name, r.requirement_name, r.type as file_type, u.status, 
                   u.rejection_reason, u.file_name as file_path, u.uploaded_at
            FROM {$prefix}lrofileupload_uploads u
            JOIN {$prefix}lrofileupload_group_requirements r ON u.id_requirement = r.id_requirement
            JOIN {$prefix}lrofileupload_product_groups g ON r.id_group = g.id_group
            WHERE u.id_customer = " . (int)$id_customer . "
            ORDER BY u.uploaded_at DESC
        ";
        return $db->executeS($sql);
    }

    public static function getRejectedUploads($id_customer)
    {
        $db = Db::getInstance();
        $prefix = _DB_PREFIX_;

        $sql = "
            SELECT u.id_requirement, g.group_name, r.requirement_name, r.type as file_type, 
                   u.rejection_reason, u.file_name as file_path, u.uploaded_at
            FROM {$prefix}lrofileupload_uploads u
            JOIN {$prefix}lrofileupload_group_requirements r ON u.id_requirement = r.id_requirement
            JOIN {$prefix}lrofileupload_product_groups g ON r.id_group = g.id_group
            WHERE u.id_customer = " . (int)$id_customer . " AND u.status = 'Rejected'
            ORDER BY u.uploaded_at DESC
        ";
        return $db->executeS($sql);
    }
}
