<?php
if (!defined('_PS_VERSION_')) { exit; }

class LrofileuploadMailer
{
    public static function notifyRejectionByUploadId($idUpload)
    {
        $db   = Db::getInstance();
        $pref = _DB_PREFIX_;

        $tblUp  = $pref.'lrofileupload_uploads';
        $tblReq = $pref.'lrofileupload_requirements';

        // pick likely column names
        $ucidCol = 'id_customer';
        $ureqCol = 'id_requirement';
        $ustat   = 'status';
        $ureas   = 'rejection_reason';
        $ridCol  = 'id_requirement';
        $rname   = 'name';

        $row = $db->getRow(
            'SELECT u.`id_upload`, u.`'.$ucidCol.'` AS cid, u.`'.$ureqCol.'` AS rid, u.`'.$ustat.'` AS ustatus, u.`'.$ureas.'` AS reason,
                    r.`'.$rname.'` AS rname
               FROM `'.$tblUp.'` u
               LEFT JOIN `'.$tblReq.'` r ON r.`'.$ridCol.'` = u.`'.$ureqCol.'`
              WHERE u.`id_upload`='.(int)$idUpload.' LIMIT 1'
        );
        if (!$row) return false;

        $cid    = (int)$row['cid'];
        $reason = trim((string)$row['reason']);
        $rnameV = (string)$row['rname'];

        $customer = new Customer($cid);
        if (!Validate::isLoadedObject($customer)) return false;

        $ctx  = Context::getContext();
        $lang = (int)$ctx->language->id;
        $link = $ctx->link->getModuleLink('lrofileupload','upload',[],true);

        $vars = [
            '{firstname}'   => $customer->firstname,
            '{lastname}'    => $customer->lastname,
            '{requirement}' => $rnameV ?: 'Document',
            '{reason}'      => $reason !== '' ? $reason : 'No reason provided',
            '{upload_url}'  => $link,
        ];

        return Mail::Send(
            $lang,
            'lro_rejected',                         // template name (below)
            'Document rejected',                    // subject
            $vars,
            $customer->email,
            $customer->firstname.' '.$customer->lastname,
            null, null, null, null,
            _PS_MODULE_DIR_.'lrofileupload/mails/'
        );
    }
}
