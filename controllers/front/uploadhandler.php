<?php
/**
 * AJAX upload handler
 * Route: index.php?fc=module&module=lrofileupload&controller=uploadhandler
 */
declare(strict_types=1);

if (!defined('_PS_VERSION_')) { exit; }

class LrofileuploadUploadhandlerModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    /* ---------- SETTINGS ---------- */
    private const BASE_STORAGE    = '/home/mfjprqzu/uploads_lrofileupload';
    private const MAX_FILE_BYTES  = 50 * 1024 * 1024; // 50 MB
    private const ALLOWED_MIME    = ['application/pdf','image/jpeg','image/pjpeg','image/png','image/gif','image/webp'];
    private const ALLOWED_EXT     = ['pdf','jpg','jpeg','png','gif','webp'];

    public function postProcess()
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                return $this->jsonError('Method not allowed', 405);
            }
            if (!$this->context->customer || !$this->context->customer->id) {
                return $this->jsonError('You must be logged in.', 401);
            }

            // CSRF (from cookie set in upload.php)
            $csrfCookie = (string)$this->context->cookie->__get('lro_csrf');
            $csrfPost   = (string)Tools::getValue('csrf');
            if (!$csrfCookie || !$csrfPost || !hash_equals($csrfCookie, $csrfPost)) {
                return $this->jsonError('Bad CSRF token', 400);
            }

            $idCustomer   = (int)$this->context->customer->id;
            $P            = _DB_PREFIX_;
            $db           = Db::getInstance();

            // Ensure schema (columns exist)
            $this->ensureSchema($db, $P);

            $id_group       = (int)Tools::getValue('id_group');
            $id_requirement = (int)Tools::getValue('id_requirement');

            if ($id_group <= 0 || $id_requirement <= 0) {
                return $this->jsonError('Missing group/requirement', 400);
            }
            if (empty($_FILES['file']['tmp_name'])) {
                return $this->jsonError('No file uploaded', 400);
            }

            // Load group and requirement
            $group = $db->getRow("SELECT * FROM {$P}lrofileupload_groups WHERE id_group=".(int)$id_group." AND active=1");
            if (!$group) return $this->jsonError('Group not found/active', 404);

            $req = $db->getRow("SELECT * FROM {$P}lrofileupload_requirements WHERE id_requirement=".(int)$id_requirement." AND id_group=".(int)$id_group." AND active=1");
            if (!$req) return $this->jsonError('Requirement not found/active', 404);

            // Check unlock: manual OR (unlock_by_purchase && purchased any assigned product)
            if (!$this->isGroupUnlockedForCustomer($db, $P, $group, $idCustomer)) {
                return $this->jsonError('This group is locked for your account.', 403);
            }

            // Validate file by requirement type
            $fileTmp = $_FILES['file']['tmp_name'];
            $orig    = $_FILES['file']['name'];
            $size    = (int)$_FILES['file']['size'];
            if ($size <= 0 || $size > static::MAX_FILE_BYTES) {
                return $this->jsonError('File too large (max 50MB) or empty', 400);
            }

            // MIME / EXT
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            $mime  = $finfo ? @finfo_file($finfo, $fileTmp) : null;
            if ($finfo) @finfo_close($finfo);
            $mime  = $mime ?: (string)($_FILES['file']['type'] ?? '');
            $ext   = strtolower(pathinfo($orig, PATHINFO_EXTENSION));

            if (!in_array($ext, static::ALLOWED_EXT, true)) {
                return $this->jsonError('File type not allowed (extension)', 400);
            }
            if (!in_array($mime, static::ALLOWED_MIME, true)) {
                return $this->jsonError('File type not allowed (MIME)', 400);
            }

            // Requirement-specific gate
            $ft = $req['file_type']; // pdf | image | any
            if ($ft === 'pdf' && $ext !== 'pdf') {
                return $this->jsonError('Only PDF allowed for this requirement.', 400);
            }
            if ($ft === 'image' && !in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) {
                return $this->jsonError('Only image files allowed for this requirement.', 400);
            }

            // Build safe destination
            $custDir  = rtrim(static::BASE_STORAGE, '/').'/'.$idCustomer;
            $groupDir = $custDir.'/group_'.$id_group;
            if (!is_dir($custDir))  @mkdir($custDir, 0775, true);
            if (!is_dir($groupDir)) @mkdir($groupDir, 0775, true);
            if (!is_dir($groupDir)) return $this->jsonError('Server storage not available', 500);

            // Randomized unique filename
            $stored = 'req'.$id_requirement.'-'.date('Ymd-His').'-'.bin2hex(random_bytes(5)).'.'.$ext;
            $dest   = $groupDir.'/'.$stored;

            if (!@move_uploaded_file($fileTmp, $dest)) {
                return $this->jsonError('Failed to store file', 500);
            }

            // Insert row (pending)
            $ok = $db->insert('lrofileupload_uploads', [
                'id_customer'     => (int)$idCustomer,
                'id_group'        => (int)$id_group,
                'id_requirement'  => (int)$id_requirement,
                'stored_name'     => pSQL($stored),
                'original_name'   => pSQL($orig),
                'mime'            => pSQL($mime),
                'ext'             => pSQL($ext),
                'size_bytes'      => (int)$size,
                'status'          => pSQL('pending'),
                'rejection_reason_id' => null,
                'rejection_note'      => null,
                'created_at'      => date('Y-m-d H:i:s'),
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);
            if (!$ok) {
                @unlink($dest);
                return $this->jsonError('DB insert failed', 500);
            }

            return $this->jsonOk([
                'message' => 'Upload received. Waiting for review.',
                'stored'  => $stored,
                'size'    => $size,
                'mime'    => $mime,
                'status'  => 'pending',
            ]);

        } catch (Throwable $e) {
            return $this->jsonError('Exception: '.$e->getMessage(), 500);
        }
    }

    /* ---------- helpers ---------- */

    private function ensureSchema(Db $db, string $P): void
    {
        $db->execute("
            CREATE TABLE IF NOT EXISTS {$P}lrofileupload_manual_unlocks (
                id_customer INT UNSIGNED NOT NULL,
                id_group    INT UNSIGNED NOT NULL,
                created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(id_customer, id_group)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        $db->execute("
            CREATE TABLE IF NOT EXISTS {$P}lrofileupload_uploads (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                id_customer INT UNSIGNED NOT NULL,
                id_group INT UNSIGNED NOT NULL,
                id_requirement INT UNSIGNED NOT NULL,
                stored_name VARCHAR(255) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                mime VARCHAR(150) NOT NULL,
                ext VARCHAR(12) NOT NULL,
                size_bytes BIGINT UNSIGNED NOT NULL,
                status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                rejection_reason_id INT UNSIGNED NULL,
                rejection_note TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY(id),
                KEY idx_customer (id_customer),
                KEY idx_group (id_group),
                KEY idx_req (id_requirement),
                KEY idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        $this->addColIfMissing($db, "{$P}lrofileupload_uploads", "id_group", "INT UNSIGNED NOT NULL");
        $this->addColIfMissing($db, "{$P}lrofileupload_uploads", "id_requirement", "INT UNSIGNED NOT NULL");
        $this->addColIfMissing($db, "{$P}lrofileupload_uploads", "status", "ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");
        $this->addColIfMissing($db, "{$P}lrofileupload_uploads", "rejection_reason_id", "INT UNSIGNED NULL");
        $this->addColIfMissing($db, "{$P}lrofileupload_uploads", "rejection_note", "TEXT NULL");
    }

    private function addColIfMissing(Db $db, string $table, string $col, string $ddl): void
    {
        $has = $db->getValue("SHOW COLUMNS FROM `{$table}` LIKE '".pSQL($col)."'");
        if (!$has) { $db->execute("ALTER TABLE `{$table}` ADD `{$col}` {$ddl}"); }
    }

    private function isGroupUnlockedForCustomer(Db $db, string $P, array $group, int $idCustomer): bool
    {
        // manual unlock?
        $manual = (bool)$db->getValue("SELECT 1 FROM {$P}lrofileupload_manual_unlocks WHERE id_customer=".(int)$idCustomer." AND id_group=".(int)$group['id_group']." LIMIT 1");
        if ($manual) return true;

        $unlockByPurchase = (int)$group['unlock_by_purchase'] === 1;
        if (!$unlockByPurchase) return false;

        // get group's assigned products
        $pids = $db->executeS("SELECT id_product FROM {$P}lrofileupload_group_products WHERE id_group=".(int)$group['id_group']);
        $assigned = array_map(fn($r)=>(int)$r['id_product'], $pids ?: []);
        if (!$assigned) return false;

        // Does the customer have any PAID orders containing any of these products?
        $list = implode(',', array_map('intval', $assigned));
        $sql  = "
            SELECT 1
            FROM {$P}orders o
            JOIN {$P}order_detail od ON od.id_order = o.id_order
            JOIN {$P}order_history oh ON oh.id_order = o.id_order
            JOIN {$P}order_state os ON os.id_order_state = oh.id_order_state
            WHERE o.id_customer = ".(int)$idCustomer."
              AND od.product_id IN ($list)
              AND os.paid = 1
            LIMIT 1
        ";
        return (bool)$db->getValue($sql);
    }

    private function jsonOk(array $payload=[]): void
    {
        echo json_encode(['success'=>true] + $payload);
        exit;
    }
    private function jsonError(string $message, int $code=400): void
    {
        http_response_code($code);
        echo json_encode(['success'=>false,'message'=>$message]);
        exit;
    }
}
