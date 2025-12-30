<?php
class LrofileuploadRecentModuleFrontController extends ModuleFrontController
{
    public $auth = true;               // must be logged in
    public $guestAllowed = false;

    public function setMedia()
    {
        parent::setMedia();
        // You can enqueue your module css/js here if you want
        // $this->registerStylesheet('lro-recent', 'modules/'.$this->module->name.'/views/css/lrofileupload.css');
    }

    public function initContent()
    {
        parent::initContent();

        $data = $this->fetchRecentDocsForCustomer(30); // pull up to 30, we’ll show per-tab

        $this->context->smarty->assign([
            'lro_recent_docs' => $data,
            'page_title'      => $this->module->l('Your Uploaded Files'),
        ]);

        $this->setTemplate('module:'.$this->module->name.'/views/templates/front/recent.tpl');
    }

    /* ---------- helpers (copied to be self-contained) ---------- */

    private function tableExists(Db $db, string $fullTable): bool
    {
        $dbName = pSQL(_DB_NAME_);
        $full   = pSQL($fullTable);
        return (bool)$db->getValue("SELECT COUNT(*) FROM information_schema.TABLES
                                    WHERE TABLE_SCHEMA='{$dbName}' AND TABLE_NAME='{$full}'");
    }

    private function colExists(Db $db, string $fullTable, string $col): bool
    {
        $dbName = pSQL(_DB_NAME_);
        $full   = pSQL($fullTable);
        $col    = pSQL($col);
        return (bool)$db->getValue("SELECT COUNT(*) FROM information_schema.COLUMNS
                                    WHERE TABLE_SCHEMA='{$dbName}' AND TABLE_NAME='{$full}' AND COLUMN_NAME='{$col}'");
    }

    private function pickCol(Db $db, string $fullTable, array $candidates, ?string $fallback = null): ?string
    {
        foreach ($candidates as $c) {
            if ($this->colExists($db, $fullTable, $c)) return $c;
        }
        return $fallback;
    }

    private function fetchRecentDocsForCustomer(int $limitTotal = 50): array
    {
        $db     = Db::getInstance();
        $prefix = _DB_PREFIX_;
        $cid    = (int)$this->context->customer->id;

        $tblUp      = $prefix.'lrofileupload_uploads';
        $customerCol= $this->pickCol($db, $tblUp, ['customer_id','id_customer','customer']);
        $fileCol    = $this->pickCol($db, $tblUp, ['file_name','filename','original_name','name']);
        $statusCol  = $this->pickCol($db, $tblUp, ['status','state']);
        $reasonCol  = $this->pickCol($db, $tblUp, ['reason','rejection_reason','reject_reason']);
        $tsCol      = $this->pickCol($db, $tblUp, ['uploaded_at','created_at','date_add','ts','created','date']);
        $groupCol   = $this->pickCol($db, $tblUp, ['group_id','id_group','groupid','gid']);

        if (!$customerCol || !$fileCol) {
            return ['recent'=>[], 'approved'=>[], 'rejected'=>[]];
        }

        $q = new DbQuery();
        $q->select('u.`'.$fileCol.'` AS file_name');
        if ($statusCol) $q->select('u.`'.$statusCol.'` AS status');
        if ($reasonCol) $q->select('u.`'.$reasonCol.'` AS reason');
        if ($tsCol)     $q->select('u.`'.$tsCol.'` AS uploaded_at');
        if ($groupCol)  $q->select('u.`'.$groupCol.'` AS group_id');

        // add group label if available
        if ($groupCol && $this->tableExists($db, $prefix.'lrofileupload_product_groups')
            && $this->colExists($db, $prefix.'lrofileupload_product_groups', 'group_name')) {
            $q->select('g.`group_name` AS group_name');
            $q->from('lrofileupload_uploads', 'u');
            $q->leftJoin('lrofileupload_product_groups', 'g', 'g.`id_group` = u.`'.$groupCol.'`');
        } else {
            $q->from('lrofileupload_uploads', 'u');
        }

        $q->where('u.`'.$customerCol.'` = '.(int)$cid);
        $q->orderBy($tsCol ? ('u.`'.$tsCol.'` DESC') : 'u.`'.$fileCol.'` DESC');
        $q->limit($limitTotal);

        $rows = $db->executeS($q) ?: [];

        $recent   = [];
        $approved = [];
        $rejected = [];

        foreach ($rows as $r) {
            $item = [
                'file_name'   => (string)($r['file_name'] ?? ''),
                'status'      => (string)($r['status'] ?? ''),
                'reason'      => (string)($r['reason'] ?? ''),
                'uploaded_at' => (string)($r['uploaded_at'] ?? ''),
                'group_id'    => isset($r['group_id']) ? (int)$r['group_id'] : null,
                'group_label' => isset($r['group_name']) ? (string)$r['group_name']
                              : (isset($r['group_id']) ? (string)$r['group_id'] : '-'),
            ];

            $recent[] = $item;
            $st = Tools::strtolower($item['status']);
            if ($st === 'approved') $approved[] = $item;
            if ($st === 'rejected') $rejected[] = $item;
        }

        return compact('recent','approved','rejected');
    }
}
