<?php
/**
 * Main module class
 */
if (!defined('_PS_VERSION_')) { exit; }

class Lrofileupload extends Module
{
    public function __construct()
    {
        $this->name = 'lrofileupload';
        $this->tab = 'front_office_features';
        $this->version = '1.0.3';
        $this->author = 'LRO';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('File Upload Module');
        $this->description = $this->l('Allows customers to upload files for products.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');

        // In case some environments don’t initialize secure_key, provide a fallback
        if (empty($this->secure_key)) {
            $this->secure_key = $this->computeSecureKey();
        }
    }

    /* --------------------------- helpers --------------------------- */

    /** Fallback secure key derived from cookie key + module name */
    public function computeSecureKey(): string
    {
        $base = defined('_COOKIE_KEY_') ? _COOKIE_KEY_ : _PS_VERSION_;
        return hash_hmac('sha256', $this->name, $base);
    }

    /** Return an always-available secure key (property if present, else computed) */
    public function getSecureKey(): string
    {
        return !empty($this->secure_key) ? (string)$this->secure_key : $this->computeSecureKey();
    }

    /** Disk path to where we store files for a customer (optionally per group) */
    public function storagePath(int $idCustomer, ?int $idGroup = null): string
    {
        // Try custom base via Configuration first
        $base = (string)Configuration::get('LRO_STORAGE_BASE');
        if ($base && is_dir($base)) {
            $root = rtrim($base, DIRECTORY_SEPARATOR);
        } else {
            // Default outside web root if possible, else module/uploads
            $try = dirname(_PS_ROOT_DIR__).'/uploads_lrofileupload';
            $root = is_dir($try) ? $try : _PS_MODULE_DIR_.$this->name.'/uploads';
        }

        $dir = $root.'/customer_'.$idCustomer;
        if ($idGroup) $dir .= '/group_'.$idGroup;
        return $dir;
    }

    /* --------------------------- install / uninstall --------------------------- */

    public function install()
    {
        if (!parent::install()) return false;

        // optional: run SQL in /install.sql
        $sqlFile = __DIR__.'/install.sql';
        if (file_exists($sqlFile)) {
            $sql = str_replace('{PREFIX}', _DB_PREFIX_, file_get_contents($sqlFile));
            foreach (preg_split('/;\s*[\r\n]+/', $sql) as $q) {
                $q = trim($q);
                if ($q && !Db::getInstance()->execute($q)) return false;
            }
        }

        $ok  = true;
        $ok &= $this->registerHook('displayHeader');
        $ok &= $this->registerHook('displayCustomerAccount');
        $ok &= $this->registerHook('displayMyAccountBlock');
        $ok &= $this->registerHook('displayMyAccountDashboard');
        $ok &= $this->registerHook('displayProductAdditionalInfo');
        $ok &= $this->registerHook('actionOrderStatusPostUpdate');

        return (bool)$ok;
    }

    public function uninstall()
    {
        return parent::uninstall();
    }

    /* --------------------------- hooks --------------------------- */

    public function hookDisplayHeader()
    {
        // Include your assets if you have them (harmless if missing)
        $this->context->controller->addCSS($this->_path.'views/css/lrofileupload.css');
        $this->context->controller->addJS($this->_path.'views/js/lrofileupload.js');
    }

    public function hookDisplayCustomerAccount()
    {
        $this->context->smarty->assign([
            'upload_url'  => $this->context->link->getModuleLink($this->name, 'upload'),
            'history_url' => $this->context->link->getModuleLink($this->name, 'history'),
        ]);
        return $this->display(__FILE__, 'views/templates/hook/my-account-upload-link.tpl');
    }

    public function hookDisplayMyAccountBlock()
    {
        return $this->hookDisplayCustomerAccount();
    }

    public function hookDisplayMyAccountDashboard()
    {
        return $this->hookDisplayCustomerAccount();
    }

    public function hookDisplayProductAdditionalInfo($params)
    {
        if (!$this->context->customer->isLogged()) {
            return $this->display(__FILE__, 'views/templates/hook/login_required.tpl');
        }
        $this->context->smarty->assign([
            'id_product' => isset($params['product']['id_product']) ? (int)$params['product']['id_product'] : 0,
        ]);
        return $this->display(__FILE__, 'views/templates/hook/upload.tpl');
    }

    public function hookActionOrderStatusPostUpdate($params)
    {
        /** @var Order|null $order */
        $order = $params['order'] ?? null;
        /** @var OrderState|null $new */
        $new   = $params['newOrderStatus'] ?? null;
        if (!$order instanceof Order) return;

        if ($new instanceof OrderState && (int)$new->paid === 1) {
            $this->unlockFromOrder((int)$order->id, (int)$order->id_customer);
            return;
        }
        if ($order->hasBeenPaid()) {
            $this->unlockFromOrder((int)$order->id, (int)$order->id_customer);
        }
    }

    private function unlockFromOrder(int $idOrder, int $idCustomer): void
    {
        $db = Db::getInstance();
        $P  = _DB_PREFIX_;
        $sql = "
            INSERT INTO {$P}lrofileupload_manual_unlocks (id_customer, id_group)
            SELECT DISTINCT {$idCustomer}, pg.id_group
            FROM {$P}order_detail od
            JOIN {$P}lrofileupload_product_groups pg ON pg.id_product = od.product_id
            JOIN {$P}lrofileupload_groups g         ON g.id_group = pg.id_group AND g.active = 1
            LEFT JOIN {$P}lrofileupload_manual_unlocks mu
                 ON mu.id_customer = {$idCustomer} AND mu.id_group = pg.id_group
            WHERE od.id_order = {$idOrder}
              AND mu.id_customer IS NULL
        ";
        try { $db->execute($sql); } catch (Exception $e) {}
    }
}
