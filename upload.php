<?php
/**
 * Customer uploads controller (Option A + status / locking)
 *
 * Route: /module/lrofileupload/upload
 *
 * Rules:
 * - Groups visible ONLY if:
 *     * Customer has a paid order with a mapped product (lrofileupload_product_groups), OR
 *     * There is an active manual unlock in lrofileupload_manual_unlocks
 * - For each (customer, group, requirement):
 *     * If latest ACTIVE row has status = 'approved' → requirement HIDDEN (done)
 *     * Otherwise                                   → upload allowed (pending/rejected/blank)
 * - Files are stored OUTSIDE public_html at:
 *     /home/mfjprqzu/uploads_lrofileupload/
 * - Folder layout: customer_<id>/g<group>/r<requirement>/
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

class LrofileuploadUploadModuleFrontController extends ModuleFrontController
{
    /** @var Db */
    protected $db;

    /** @var string */
    protected $prefix;

    /** tables (without prefix) */
    protected $uploadsTbl       = 'lrofileupload_uploads';
    protected $groupsTbl        = 'lrofileupload_groups';
    protected $requirementsTbl  = 'lrofileupload_document_requirements';
    protected $productGroupsTbl = 'lrofileupload_product_groups';
    protected $manualUnlocksTbl = 'lrofileupload_manual_unlocks';

    /** absolute storage path OUTSIDE public_html */
    protected $storageRoot = '/home/mfjprqzu/uploads_lrofileupload/';

    /** health/version tag */
    protected $version = 'lrofu-2025-11-25A2';

    /** uploads table column cache */
    protected $uploadsCols = [];

    /** enforce SSL on this controller */
    public $ssl = true;

    public function __construct()
    {
        parent::__construct();

        $this->db         = Db::getInstance();
        $this->prefix     = _DB_PREFIX_;
        $this->uploadsCols = $this->describeTableCols();
    }

    /* ---------------------------------------------------- *
     * Utilities
     * ---------------------------------------------------- */

    protected function jsonOut(array $data, int $code = 200): void
    {
        header('Content-Type: application/json; charset=utf-8', true, $code);
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** DESCRIBE uploads table to know which columns exist */
    protected function describeTableCols(): array
    {
        if (!$this->tableExists($this->uploadsTbl)) {
            return [];
        }

        $table = $this->prefix . $this->uploadsTbl;
        $rows  = $this->db->executeS('DESCRIBE `'.$table.'`');
        $map   = [];

        foreach ($rows ?: [] as $r) {
            $map[trim($r['Field'])] = true;
        }
        return $map;
    }

    protected function colExists(string $c): bool
    {
        return isset($this->uploadsCols[$c]);
    }

    /** Pick the first existing column name from a list */
    protected function pickCol(array $cands): ?string
    {
        foreach ($cands as $c) {
            if ($this->colExists($c)) {
                return $c;
            }
        }
        return null;
    }

    /** Safe table existence check (no SHOW/LIMIT clash) */
    protected function tableExists(string $tableWithoutPrefix): bool
    {
        $table = pSQL($this->prefix.$tableWithoutPrefix);
        $sql = '
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name   = "'.$table.'"
        ';
        return (bool) $this->db->getValue($sql);
    }

    protected function ensureDir(string $path): void
    {
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }
        if (!is_writable($path)) {
            @chmod($path, 0775);
        }
    }

    protected function safeFileBase(string $original): string
    {
        $name = pathinfo($original, PATHINFO_FILENAME);
        $ext  = pathinfo($original, PATHINFO_EXTENSION);
        $name = preg_replace('~[^a-zA-Z0-9_.-]+~', '_', $name);
        $rand = bin2hex(random_bytes(8));

        return $rand . '_' . $name . ($ext ? '.'.$ext : '');
    }

    /** Return first row safely without Db::getRow()/double-LIMIT */
    protected function firstRow(DbQuery $q): ?array
    {
        $q->limit(1);
        $rows = $this->db->executeS($q);

        return ($rows && isset($rows[0])) ? $rows[0] : null;
    }

    /* ---------------------------------------------------- *
     * Health probe
     * ---------------------------------------------------- */

    protected function debugHealth(): void
    {
        $exists = [
            'groups'        => $this->tableExists($this->groupsTbl),
            'uploads'       => $this->tableExists($this->uploadsTbl),
            'reqs'          => $this->tableExists($this->requirementsTbl),
            'prod_groups'   => $this->tableExists($this->productGroupsTbl),
            'manual_unlock' => $this->tableExists($this->manualUnlocksTbl),
            'storage_root_exists' => is_dir($this->storageRoot),
        ];

        $this->jsonOut([
            'success'   => true,
            'version'   => $this->version,
            'exists'    => $exists,
            'db_prefix' => $this->prefix,
            'storage'   => rtrim($this->storageRoot, '/'),
        ]);
    }

    /* ---------------------------------------------------- *
     * Lookups / status helpers
     * ---------------------------------------------------- */

    /** Newest ACTIVE row for (customer, requirement). */
    protected function findLiveReqRowOnly(int $id_customer, int $id_requirement): ?array
    {
        $q = new DbQuery();
        $q->select('*')
          ->from($this->uploadsTbl)
          ->where('id_customer='.(int)$id_customer)
          ->where('id_requirement='.(int)$id_requirement)
          ->where('is_active=1')
          ->orderBy('id_upload DESC');

        return $this->firstRow($q);
    }

    /** Fallback: newest row for (customer, group) ignoring requirement (legacy/null rows). */
    protected function findLegacyRow(int $id_customer, int $id_group): ?array
    {
        $q = new DbQuery();
        $q->select('*')
          ->from($this->uploadsTbl)
          ->where('id_customer='.(int)$id_customer)
          ->where('id_group='.(int)$id_group)
          ->orderBy('id_upload DESC');

        return $this->firstRow($q);
    }

    /** Prefer requirement row; fallback to newest by group. */
    protected function findLiveRowForUi(int $id_customer, int $id_requirement, int $id_group): ?array
    {
        $row = $this->findLiveReqRowOnly($id_customer, $id_requirement);
        if ($row) {
            return $row;
        }
        return $this->findLegacyRow($id_customer, $id_group);
    }

    protected function statusLabel(string $raw): string
    {
        $raw = Tools::strtolower(trim($raw));
        switch ($raw) {
            case 'approved': return 'Approved';
            case 'rejected': return 'Rejected';
            case 'pending':  return 'Pending';
            default:         return 'Not uploaded';
        }
    }

    /**
     * Get last status + reason for (customer, group, requirement)
     */
    protected function lastStatus(int $id_group, int $id_requirement): array
    {
        $idCustomer = (int) $this->context->customer->id;

        if (!$idCustomer || !$this->tableExists($this->uploadsTbl)) {
            return ['status' => '', 'reason' => ''];
        }

        $row = $this->findLiveRowForUi($idCustomer, (int)$id_requirement, (int)$id_group);
        if (!$row) {
            return ['status' => '', 'reason' => ''];
        }

        $statusCol = $this->pickCol(['status']);
        $reasonCol = $this->pickCol(['rejection_reason', 'rejection_notes']);

        $status = $statusCol && isset($row[$statusCol]) ? (string)$row[$statusCol] : '';
        $reason = $reasonCol && isset($row[$reasonCol]) ? (string)$row[$reasonCol] : '';

        return [
            'status' => $status,
            'reason' => $reason,
        ];
    }

    /**
     * Requirements for a group, decorated with last status
     * Uses: psfc_lrofileupload_document_requirements
     * IMPORTANT:
     *   - Approved requirements are **skipped** completely (hidden from upload page)
     *   - Pending / Rejected / Blank are shown
     */
    protected function requirementsForGroup(int $id_group): array
    {
        if (!$this->tableExists($this->requirementsTbl)) {
            return [];
        }

        $q = new DbQuery();
        $q->select('id_requirement, document_name, description, required, sort_order')
          ->from($this->requirementsTbl)
          ->where('id_group='.(int)$id_group)
          ->orderBy('sort_order, id_requirement');

        $rows = $this->db->executeS($q) ?: [];
        $out  = [];

        foreach ($rows as $r) {
            $rid   = (int)$r['id_requirement'];
            $last  = $this->lastStatus($id_group, $rid);
            $raw   = Tools::strtolower(trim((string)$last['status']));

            // HIDE approved
            if ($raw === 'approved') {
                continue;
            }

            $out[$rid] = [
                'id_requirement' => $rid,
                'title'          => (string)$r['document_name'],
                'description'    => (string)$r['description'],
                'required'       => (int)$r['required'] ? 1 : 0,
                'last_status'    => $last['status'],
                'last_reason'    => $last['reason'],
                'status_label'   => $this->statusLabel((string)$last['status']),
                // Customer may upload if NOT approved (we already filtered approved out)
                'can_submit'     => true,
            ];
        }
        return $out;
    }

    /**
     * Check if latest ACTIVE row is approved (true = locked)
     * Used as a hard server-side lock for upload requests.
     */
    protected function isLockedApproved(int $id_customer, int $id_group, int $id_requirement): bool
    {
        if (!$this->tableExists($this->uploadsTbl)) {
            return false;
        }

        $q = new DbQuery();
        $q->select('status')
          ->from($this->uploadsTbl)
          ->where('id_customer='.(int)$id_customer)
          ->where('id_group='.(int)$id_group)
          ->where('id_requirement='.(int)$id_requirement)
          ->where('is_active=1')
          ->orderBy('id_upload DESC');

        $row = $this->firstRow($q);
        if (!$row || !isset($row['status'])) {
            return false;
        }

        return Tools::strtolower(trim((string)$row['status'])) === 'approved';
    }

    /* ---------------------------------------------------- *
     * Group unlock logic (Option A)
     * ---------------------------------------------------- */

    /**
     * Return array of id_group that are open for this customer
     * (paid product OR manual unlock)
     */
    protected function getOpenGroupIds(int $idCustomer): array
    {
        $open = [];
        $P    = $this->prefix;

        // 1) From paid orders → products → groups
        if ($this->tableExists($this->groupsTbl) && $this->tableExists($this->productGroupsTbl)) {

            $orderedPids = [];
            try {
                $rows = $this->db->executeS("
                    SELECT DISTINCT od.product_id
                    FROM {$P}orders o
                    INNER JOIN {$P}order_detail od ON od.id_order = o.id_order
                    INNER JOIN {$P}order_state  os ON os.id_order_state = o.current_state
                    WHERE o.id_customer = ".(int)$idCustomer."
                      AND os.paid = 1
                ") ?: [];
                foreach ($rows as $r) {
                    $pid = (int)$r['product_id'];
                    if ($pid > 0) {
                        $orderedPids[] = $pid;
                    }
                }
            } catch (\Throwable $e) {
                $orderedPids = [];
            }

            if (!empty($orderedPids)) {
                $in   = implode(',', array_map('intval', $orderedPids));
                $rows = $this->db->executeS("
                    SELECT DISTINCT g.id_group
                    FROM {$P}{$this->productGroupsTbl} pg
                    INNER JOIN {$P}{$this->groupsTbl} g ON g.id_group = pg.id_group
                    WHERE g.active = 1
                      AND pg.id_product IN ({$in})
                ") ?: [];

                foreach ($rows as $r) {
                    $open[(int)$r['id_group']] = true;
                }
            }
        }

        // 2) Manual unlocks
        if ($this->tableExists($this->manualUnlocksTbl) && $this->tableExists($this->groupsTbl)) {
            $rows = $this->db->executeS("
                SELECT DISTINCT mu.id_group
                FROM {$P}{$this->manualUnlocksTbl} mu
                INNER JOIN {$P}{$this->groupsTbl} g ON g.id_group = mu.id_group
                WHERE mu.id_customer = ".(int)$idCustomer."
                  AND g.active = 1
                  AND (mu.expires_at IS NULL OR mu.expires_at > NOW())
                  AND (mu.is_active = 1 OR mu.is_active IS NULL)
            ") ?: [];

            foreach ($rows as $r) {
                $open[(int)$r['id_group']] = true;
            }
        }

        ksort($open);
        return array_keys($open);
    }

    /**
     * Build $cards payload expected by upload.tpl
     */
    protected function buildCards(): array
    {
        $idCustomer = (int)$this->context->customer->id;
        if (!$idCustomer || !$this->tableExists($this->groupsTbl)) {
            return [];
        }

        $openGroupIds = $this->getOpenGroupIds($idCustomer);
        if (!$openGroupIds) {
            return [];
        }

        $in = implode(',', array_map('intval', $openGroupIds));

        $q = new DbQuery();
        // groups table has: id_group, group_name, description, sort_order, active
        $q->select('id_group, group_name, description, sort_order, active')
          ->from($this->groupsTbl)
          ->where('active = 1')
          ->where('id_group IN ('.$in.')')
          ->orderBy('sort_order, id_group');

        $groups = $this->db->executeS($q) ?: [];
        $cards  = [];

        foreach ($groups as $g) {
            $gid = (int)$g['id_group'];

            $reqs = array_values($this->requirementsForGroup($gid));
            // If this group has no visible requirements (all approved), skip it
            if (!$reqs) {
                continue;
            }

            $cards[] = [
                'id_group'     => $gid,
                'name'         => (string)$g['group_name'],
                'desc'         => (string)$g['description'],
                'requirements' => $reqs,
            ];
        }

        return $cards;
    }

    /* ---------------------------------------------------- *
     * Upload handling
     * ---------------------------------------------------- */

    protected function processUpload(): void
    {
        $id_customer = (int)$this->context->customer->id;
        if (!$id_customer) {
            $this->jsonOut(['success'=>false,'message'=>'Please sign in first.'], 401);
        }

        $id_group       = (int) Tools::getValue('id_group', 0);
        $id_requirement = (int) Tools::getValue('id_requirement', 0);

        if ($id_group <= 0 || $id_requirement <= 0) {
            $this->jsonOut(['success'=>false,'message'=>'Missing group or requirement.'], 422);
        }

        // Hard lock: if already approved, block re-upload
        if ($this->isLockedApproved($id_customer, $id_group, $id_requirement)) {
            $this->jsonOut([
                'success' => false,
                'message' => 'This document is already approved and locked. Please contact support if you need to update it.',
            ], 423);
        }

        if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            $this->jsonOut(['success'=>false,'message'=>'No file uploaded.'], 400);
        }

        $file = $_FILES['file'];

        // Basic validations
        $maxMB = 30;
        if ($file['size'] <= 0 || $file['size'] > ($maxMB * 1024 * 1024)) {
            $this->jsonOut(['success'=>false,'message'=>"Invalid file size. Max {$maxMB}MB."], 400);
        }

        $ext     = Tools::strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf','jpg','jpeg','png'];
        if (!in_array($ext, $allowed, true)) {
            $this->jsonOut(['success'=>false,'message'=>'Only PDF/JPG/PNG allowed.'], 415);
        }

        // Storage path
        $baseDir = rtrim($this->storageRoot, '/')
                 . '/customer_' . (int)$id_customer
                 . '/g' . (int)$id_group
                 . '/r' . (int)$id_requirement
                 . '/';

        $this->ensureDir($baseDir);

        $basename = $this->safeFileBase($file['name']);
        $finalAbs = $baseDir . $basename;

        if (!@move_uploaded_file($file['tmp_name'], $finalAbs)) {
            $this->jsonOut(['success'=>false,'message'=>'Failed to save uploaded file.'], 500);
        }
        @chmod($finalAbs, 0644);

        // DB: archive previous active then insert new active
        if ($this->tableExists($this->uploadsTbl)) {
            $this->db->execute('START TRANSACTION');

            try {
                // Archive existing ACTIVE row
                $setParts = ['is_active=0'];
                if ($this->colExists('archived_at')) {
                    $setParts[] = 'archived_at=NOW()';
                }

                $archiveSql = '
                    UPDATE `'.$this->prefix.$this->uploadsTbl.'`
                    SET '.implode(', ', $setParts).'
                    WHERE id_customer='.(int)$id_customer.'
                      AND id_group='.(int)$id_group.'
                      AND id_requirement='.(int)$id_requirement.'
                      AND is_active=1
                ';
                $this->db->execute($archiveSql);

                // Insert new ACTIVE row (status=pending)
                $ins = [
                    'id_customer'    => (int)$id_customer,
                    'id_group'       => (int)$id_group,
                    'id_requirement' => (int)$id_requirement,
                    'is_active'      => 1,
                    'file_name'      => pSQL($finalAbs),
                    'original_name'  => pSQL($file['name']),
                    'status'         => 'pending',
                    'date_uploaded'  => date('Y-m-d H:i:s'),
                    'uploaded_at'    => date('Y-m-d H:i:s'),
                ];

                // Only insert columns that actually exist
                foreach (array_keys($ins) as $c) {
                    if (!$this->colExists($c)) {
                        unset($ins[$c]);
                    }
                }

                $ok = $this->db->insert($this->uploadsTbl, $ins, false, true, Db::INSERT);
                if (!$ok) {
                    throw new Exception('Insert failed: '.$this->db->getMsgError());
                }

                $this->db->execute('COMMIT');
            } catch (Exception $e) {
                $this->db->execute('ROLLBACK');
                if (is_file($finalAbs)) {
                    @unlink($finalAbs);
                }
                $this->jsonOut([
                    'success'=>false,
                    'message'=>'Server error: '.$e->getMessage(),
                ], 500);
            }
        }

        $this->jsonOut([
            'success' => true,
            'message' => 'Uploaded.',
            'file'    => [
                'saved_as' => $finalAbs,
                'name'     => $file['name'],
                'ext'      => $ext,
            ],
        ]);
    }

    /* ---------------------------------------------------- *
     * Controller lifecycle
     * ---------------------------------------------------- */

    public function initContent()
    {
        parent::initContent();

        // Require customer login
        if (!$this->context->customer || !$this->context->customer->isLogged()) {
            Tools::redirect(
                'index.php?controller=authentication&back=' .
                urlencode($this->context->link->getModuleLink($this->module->name, 'upload', [], true))
            );
            return;
        }

        // Health check endpoint
        if (Tools::getValue('health')) {
            $this->debugHealth();
            return;
        }

        // AJAX upload
        if (Tools::isSubmit('id_group') && isset($_FILES['file'])) {
            $this->processUpload();
            return;
        }

        // Render page
        $cards = $this->buildCards();

        $this->context->smarty->assign([
            'cards'      => $cards,
            'max_mb'     => 30,
            'upload_url' => $this->context->link->getModuleLink($this->module->name, 'upload'),
        ]);

        $this->setTemplate('module:lrofileupload/views/templates/front/upload.tpl');
    }
}
