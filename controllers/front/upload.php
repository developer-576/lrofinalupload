<?php
/**
 * modules/lrofileupload/controllers/front/upload.php
 *
 * Customer uploads controller – B3 with per-requirement visibility
 *
 * Route: /module/lrofileupload/upload
 *
 * Visibility (per requirement):
 *   - Latest ACTIVE status = 'approved' -> HIDDEN
 *   - Latest ACTIVE status = 'pending'  -> HIDDEN
 *   - Latest ACTIVE status = 'rejected' -> SHOWN (with reason)
 *   - No row                            -> SHOWN
 *
 * Group unlock:
 *   - Open if customer has a PAID order containing a product
 *     mapped in psfc_lrofileupload_group_products, OR
 *   - Open via psfc_lrofileupload_manual_unlocks
 *
 * Storage:
 *   /home/mfjprqzu/uploads_lrofileupload/customer_<id>/g<group>/r<requirement>/
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

    /** Tables (without prefix) */
    protected $uploadsTbl       = 'lrofileupload_uploads';
    protected $groupsTbl        = 'lrofileupload_groups';
    protected $requirementsTbl  = 'lrofileupload_requirements';
    protected $productGroupsTbl = 'lrofileupload_group_products';
    protected $manualUnlocksTbl = 'lrofileupload_manual_unlocks';

    /** Absolute storage path OUTSIDE public_html */
    protected $storageRoot = '/home/mfjprqzu/uploads_lrofileupload/';

    /** Health/version tag */
    protected $version = 'lrofu-2025-11-27-B3';

    /** Uploads table column cache */
    protected $uploadsCols = [];

    /** Max file size in MB */
    protected $maxFileMB = 100;

    /** Enforce SSL on this controller */
    public $ssl = true;

    public function __construct()
    {
        parent::__construct();

        $this->db     = Db::getInstance();
        $this->prefix = _DB_PREFIX_;

        // Cache uploads table columns if it exists
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

    /** Safe table existence check */
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

    /** Return first row safely */
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
            'groups'              => $this->tableExists($this->groupsTbl),
            'uploads'             => $this->tableExists($this->uploadsTbl),
            'reqs'                => $this->tableExists($this->requirementsTbl),
            'prod_groups'         => $this->tableExists($this->productGroupsTbl),
            'manual_unlock'       => $this->tableExists($this->manualUnlocksTbl),
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

    /**
     * Get last status + reason for EXACT (customer, group, requirement).
     * No fallback to group-level rows – avoids hiding other requirements.
     */
    protected function lastStatus(int $id_group, int $id_requirement): array
    {
        $idCustomer = (int) $this->context->customer->id;

        if (!$idCustomer || !$this->tableExists($this->uploadsTbl)) {
            return ['status' => '', 'reason' => ''];
        }

        $q = new DbQuery();
        $q->select('*')
          ->from($this->uploadsTbl)
          ->where('id_customer='.(int)$idCustomer)
          ->where('id_group='.(int)$id_group)
          ->where('id_requirement='.(int)$id_requirement)
          ->where('is_active=1')
          ->orderBy('id_upload DESC');

        $row = $this->firstRow($q);
        if (!$row) {
            return ['status' => '', 'reason' => ''];
        }

        $status = isset($row['status']) ? (string)$row['status'] : '';

        $reasonCol = null;
        foreach (['rejection_reason', 'rejection_notes'] as $cand) {
            if (array_key_exists($cand, $row)) {
                $reasonCol = $cand;
                break;
            }
        }

        $reason = $reasonCol ? (string)$row[$reasonCol] : '';

        return [
            'status' => $status,
            'reason' => $reason,
        ];
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
     * Requirements for a group, decorated with last status
     *
     * Visibility rule (per requirement):
     *   - hide when status in ('approved','pending')
     *   - show otherwise (rejected or never uploaded)
     */
    protected function requirementsForGroup(int $id_group): array
    {
        if (!$this->tableExists($this->requirementsTbl)) {
            return [];
        }

        $q = new DbQuery();
        $q->select('id_requirement, title, description, required, sort_order, file_type, active')
          ->from($this->requirementsTbl)
          ->where('id_group='.(int)$id_group)
          ->where('active = 1')
          ->orderBy('sort_order, id_requirement');

        $rows = $this->db->executeS($q) ?: [];
        $out  = [];

        foreach ($rows as $r) {
            $rid  = (int)$r['id_requirement'];
            $last = $this->lastStatus($id_group, $rid);
            $raw  = Tools::strtolower(trim((string)$last['status']));

            // HIDE approved + pending (already uploaded / waiting review)
            if ($raw === 'approved' || $raw === 'pending') {
                continue;
            }

            $out[$rid] = [
                'id_requirement' => $rid,
                'title'          => (string)$r['title'],
                'description'    => (string)$r['description'],
                'required'       => (int)$r['required'] ? 1 : 0,
                'file_type'      => (string)$r['file_type'], // 'pdf' | 'image' | 'any'
                'last_status'    => $last['status'],
                'last_reason'    => $last['reason'],
                'status_label'   => $this->statusLabel((string)$last['status']),
                'can_submit'     => true,
            ];
        }
        return $out;
    }

    /**
     * Hard lock: if latest ACTIVE row for this requirement is approved (true = locked)
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
     * Group unlock logic
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
                $gpT  = $P.$this->productGroupsTbl;
                $gT   = $P.$this->groupsTbl;

                $rows = $this->db->executeS("
                    SELECT DISTINCT g.id_group
                    FROM {$gpT} pg
                    INNER JOIN {$gT} g ON g.id_group = pg.id_group
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
            $muT = $P.$this->manualUnlocksTbl;
            $gT  = $P.$this->groupsTbl;

            $rows = $this->db->executeS("
                SELECT DISTINCT mu.id_group
                FROM {$muT} mu
                INNER JOIN {$gT} g ON g.id_group = mu.id_group
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
        $q->select('id_group, name, description, sort_order, active')
          ->from($this->groupsTbl)
          ->where('active = 1')
          ->where('id_group IN ('.$in.')')
          ->orderBy('sort_order, id_group');

        $groups = $this->db->executeS($q) ?: [];
        $cards  = [];

        foreach ($groups as $g) {
            $gid = (int)$g['id_group'];

            $reqs = array_values($this->requirementsForGroup($gid));
            if (!$reqs) {
                // All requirements for this group are approved/pending; nothing for user to see.
                continue;
            }

            $cards[] = [
                'id_group'     => $gid,
                'name'         => (string)$g['name'],
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
            $this->jsonOut(['success' => false, 'message' => 'Please sign in first.'], 401);
        }

        $id_group       = (int) Tools::getValue('id_group', 0);
        $id_requirement = (int) Tools::getValue('id_requirement', 0);

        if ($id_group <= 0 || $id_requirement <= 0) {
            $this->jsonOut(['success' => false, 'message' => 'Missing group or requirement.'], 422);
        }

        // Hard lock: if already approved, block re-upload
        if ($this->isLockedApproved($id_customer, $id_group, $id_requirement)) {
            $this->jsonOut([
                'success' => false,
                'message' => 'This document is already approved and locked. Please contact support if you need to update it.',
            ], 423);
        }

        if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            $this->jsonOut(['success' => false, 'message' => 'No file uploaded.'], 400);
        }

        $file  = $_FILES['file'];
        $maxMB = (int)$this->maxFileMB;

        if ($file['size'] <= 0 || $file['size'] > ($maxMB * 1024 * 1024)) {
            $this->jsonOut(['success' => false, 'message' => "Invalid file size. Max {$maxMB}MB."], 400);
        }

        $ext     = Tools::strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
        if (!in_array($ext, $allowed, true)) {
            $this->jsonOut(['success' => false, 'message' => 'Only PDF/JPG/PNG allowed.'], 415);
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
            $this->jsonOut(['success' => false, 'message' => 'Failed to save uploaded file.'], 500);
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
                    'size'           => (int)$file['size'],
                    'mime'           => pSQL($file['type']),
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
                    'success' => false,
                    'message' => 'Server error: '.$e->getMessage(),
                ], 500);
            }
        }

        $this->jsonOut([
            'success' => true,
            'message' => 'Your file has been uploaded securely and is waiting for review.',
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
            'max_mb'     => (int)$this->maxFileMB,
            'upload_url' => $this->context->link->getModuleLink($this->module->name, 'upload'),
        ]);

        $this->setTemplate('module:lrofileupload/views/templates/front/upload.tpl');
    }
}
