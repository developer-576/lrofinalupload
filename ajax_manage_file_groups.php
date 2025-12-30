<?php
/**************************************************
 * AJAX for Manage File Groups
 **************************************************/
declare(strict_types=1);
ini_set('display_errors','1'); ini_set('display_startup_errors','1'); error_reporting(E_ALL);

/* bootstrap */
(function () {
    $dir = __DIR__;
    for ($i=0;$i<8;$i++){
        if(file_exists($dir.'/config/config.inc.php') && file_exists($dir.'/init.php')){
            require_once $dir.'/config/config.inc.php'; require_once $dir.'/init.php'; return;
        }
        $dir=dirname($dir);
    }
    $root=dirname(__DIR__,3);
    if(file_exists($root.'/config/config.inc.php') && file_exists($root.'/init.php')){
        require_once $root.'/config/config.inc.php'; require_once $root.'/init.php'; return;
    }
    header('Content-Type: application/json'); http_response_code(500); echo json_encode(['success'=>false,'message'=>'Bootstrap failed']); exit;
})();

if (session_status() === PHP_SESSION_NONE) session_start();

/* auth */
function lro_require_admin_ajax():void{
    if(is_file(__DIR__.'/auth.php')){ require_once __DIR__.'/auth.php'; if(function_exists('require_admin_login')){ require_admin_login(false); return; } }
    if(empty($_SESSION['admin_id'])){ header('Content-Type: application/json'); http_response_code(403); echo json_encode(['success'=>false,'message'=>'Forbidden']); exit; }
}
lro_require_admin_ajax();

/* csrf (POST only) */
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    if(empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])){
        header('Content-Type: application/json'); http_response_code(400); echo json_encode(['success'=>false,'message'=>'Bad CSRF']); exit;
    }
}

/* pdo */
function pdo():PDO{
    $db=Db::getInstance();
    foreach(['getLink','getPDO'] as $m){ if(method_exists($db,$m)){ try{ $rm=new ReflectionMethod($db,$m); $rm->setAccessible(true); $pdo=$rm->invoke($db); if($pdo instanceof PDO) return $pdo; }catch(Throwable){} } }
    try{ $ro=new ReflectionObject($db); if($ro->hasProperty('link')){ $p=$ro->getProperty('link'); $p->setAccessible(true); $pdo=$p->getValue($db); if($pdo instanceof PDO) return $pdo; } }catch(Throwable){}
    throw new RuntimeException('PDO unavailable');
}
$pdo=pdo(); $prefix=defined('_DB_PREFIX_')?_DB_PREFIX_:'ps_';
$t_groups=$prefix.'lrofileupload_groups'; $t_grpprods=$prefix.'lrofileupload_group_products'; $t_require=$prefix.'lrofileupload_requirements';

/* schema utils */
function cExists(PDO $pdo,string $t,string $c):bool{$s=$pdo->prepare("SHOW COLUMNS FROM `$t` LIKE :c");$s->execute([':c'=>$c]);return (bool)$s->fetch();}
function tryRename(PDO $pdo,string $t,string $from,string $to,string $def):void{$s=$pdo->prepare("SHOW COLUMNS FROM `$t` LIKE :c");$s->execute([':c'=>$from]); if($s->fetch()) $pdo->exec("ALTER TABLE `$t` CHANGE `$from` `$to` $def");}
function ensure_groups(PDO $pdo,string $t):void{
    try{$pdo->query("SELECT 1 FROM `$t` LIMIT 1")->fetch();}
    catch(Throwable){$pdo->exec("CREATE TABLE `$t`(id_group INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(255) NOT NULL,description TEXT NULL,active TINYINT(1) NOT NULL DEFAULT 1,sort_order INT NOT NULL DEFAULT 0,unlock_by_purchase TINYINT(1) NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");}
    if(!cExists($pdo,$t,'name')){ tryRename($pdo,$t,'group_name','name','VARCHAR(255) NOT NULL'); tryRename($pdo,$t,'title','name','VARCHAR(255) NOT NULL'); if(!cExists($pdo,$t,'name')) $pdo->exec("ALTER TABLE `$t` ADD name VARCHAR(255) NOT NULL AFTER id_group");}
    if(!cExists($pdo,$t,'description')){ tryRename($pdo,$t,'desc','description','TEXT NULL'); if(!cExists($pdo,$t,'description')) $pdo->exec("ALTER TABLE `$t` ADD description TEXT NULL AFTER name");}
    if(!cExists($pdo,$t,'active')){ tryRename($pdo,$t,'is_active','active','TINYINT(1) NOT NULL DEFAULT 1'); if(!cExists($pdo,$t,'active')) $pdo->exec("ALTER TABLE `$t` ADD active TINYINT(1) NOT NULL DEFAULT 1 AFTER description");}
    if(!cExists($pdo,$t,'sort_order')) $pdo->exec("ALTER TABLE `$t` ADD sort_order INT NOT NULL DEFAULT 0 AFTER active");
    if(!cExists($pdo,$t,'unlock_by_purchase')) $pdo->exec("ALTER TABLE `$t` ADD unlock_by_purchase TINYINT(1) NOT NULL DEFAULT 1 AFTER sort_order");
}
function ensure_requirements(PDO $pdo,string $t):void{
    try{$pdo->query("SELECT 1 FROM `$t` LIMIT 1")->fetch();}
    catch(Throwable){$pdo->exec("CREATE TABLE `$t`(id_requirement INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,id_group INT UNSIGNED NOT NULL,title VARCHAR(255) NOT NULL,file_type ENUM('pdf','image','any') NOT NULL DEFAULT 'pdf',required TINYINT(1) NOT NULL DEFAULT 1,description TEXT NULL,sort_order INT NOT NULL DEFAULT 0,active TINYINT(1) NOT NULL DEFAULT 1,KEY idx_req_group(id_group)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");}
    if(!cExists($pdo,$t,'title')){ tryRename($pdo,$t,'name','title','VARCHAR(255) NOT NULL'); tryRename($pdo,$t,'label','title','VARCHAR(255) NOT NULL'); tryRename($pdo,$t,'req_title','title','VARCHAR(255) NOT NULL'); if(!cExists($pdo,$t,'title')) $pdo->exec("ALTER TABLE `$t` ADD title VARCHAR(255) NOT NULL AFTER id_group");}
    if(!cExists($pdo,$t,'file_type')) $pdo->exec("ALTER TABLE `$t` ADD file_type ENUM('pdf','image','any') NOT NULL DEFAULT 'pdf' AFTER title");
    if(!cExists($pdo,$t,'required')){ tryRename($pdo,$t,'is_required','required','TINYINT(1) NOT NULL DEFAULT 1'); if(!cExists($pdo,$t,'required')) $pdo->exec("ALTER TABLE `$t` ADD required TINYINT(1) NOT NULL DEFAULT 1 AFTER file_type");}
    if(!cExists($pdo,$t,'description')){ tryRename($pdo,$t,'desc','description','TEXT NULL'); if(!cExists($pdo,$t,'description')) $pdo->exec("ALTER TABLE `$t` ADD description TEXT NULL AFTER required");}
    if(!cExists($pdo,$t,'sort_order')) $pdo->exec("ALTER TABLE `$t` ADD sort_order INT NOT NULL DEFAULT 0 AFTER description");
    if(!cExists($pdo,$t,'active')){ tryRename($pdo,$t,'is_active','active','TINYINT(1) NOT NULL DEFAULT 1'); if(!cExists($pdo,$t,'active')) $pdo->exec("ALTER TABLE `$t` ADD active TINYINT(1) NOT NULL DEFAULT 1 AFTER sort_order");}
}
ensure_groups($pdo,$t_groups); ensure_requirements($pdo,$t_require);

function ok($x=[]){ header('Content-Type: application/json'); echo json_encode(['success'=>true]+$x); exit; }
function err($m,$x=[]){ header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>$m]+$x); exit; }

$action=$_REQUEST['action']??'';
try{
    switch($action){
        case 'search_products': {
            $q=trim((string)($_GET['q']??'')); $langId=(int)Context::getContext()->language->id; $pl=$prefix.'product_lang'; $pp=$prefix.'product';
            if($q==='') $stmt=$pdo->query("SELECT p.id_product,pl.name FROM `$pp` p JOIN `$pl` pl ON(pl.id_product=p.id_product AND pl.id_lang=$langId) WHERE p.active=1 ORDER BY p.id_product DESC LIMIT 20");
            else { $stmt=$pdo->prepare("SELECT p.id_product,pl.name FROM `$pp` p JOIN `$pl` pl ON(pl.id_product=p.id_product AND pl.id_lang=:l) WHERE p.active=1 AND (pl.name LIKE :q OR p.id_product=:id) ORDER BY pl.name ASC LIMIT 50"); $stmt->execute([':l'=>$langId,':q'=>'%'.$q.'%',':id'=>ctype_digit($q)?(int)$q:0]); }
            $items=array_map(fn($r)=>['id'=>(int)$r['id_product'],'text'=>$r['name']?:('#'.$r['id_product'])],$stmt->fetchAll(PDO::FETCH_ASSOC));
            ok(['items'=>$items]);
        }
        case 'assign_product': {
            $gid=(int)($_POST['id_group']??0); $pid=(int)($_POST['id_product']??0); if(!$gid||!$pid) err('Missing gid/pid');
            $pdo->prepare("INSERT IGNORE INTO `$t_grpprods` (id_group,id_product) VALUES (:g,:p)")->execute([':g'=>$gid,':p'=>$pid]); ok();
        }
        case 'unassign_product': {
            $gid=(int)($_POST['id_group']??0); $pid=(int)($_POST['id_product']??0); if(!$gid||!$pid) err('Missing gid/pid');
            $pdo->prepare("DELETE FROM `$t_grpprods` WHERE id_group=:g AND id_product=:p")->execute([':g'=>$gid,':p'=>$pid]); ok();
        }
        case 'create_group': {
            $name=trim((string)($_POST['name']??'')); if($name==='') err('Name required');
            $desc=(string)($_POST['description']??''); $active=(int)($_POST['active']??1); $unlock=(int)($_POST['unlock_by_purchase']??1);
            $so=(int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM `$t_groups`")->fetchColumn()+1;
            $pdo->prepare("INSERT INTO `$t_groups` (name,description,active,sort_order,unlock_by_purchase) VALUES(:n,:d,:a,:s,:u)")
                ->execute([':n'=>$name,':d'=>$desc,':a'=>$active,':s'=>$so,':u'=>$unlock]);
            ok(['id_group'=>(int)$pdo->lastInsertId()]);
        }
        case 'update_group_field': {
            $gid=(int)($_POST['id_group']??0); $field=(string)($_POST['field']??''); $val=(string)($_POST['value']??''); if(!$gid) err('Missing group');
            $allowed=['name','description','active','unlock_by_purchase']; if(!in_array($field,$allowed,true)) err('Field not allowed');
            $pdo->prepare("UPDATE `$t_groups` SET `$field`=:v WHERE id_group=:g")->execute([':v'=>$val,':g'=>$gid]); ok();
        }
        case 'delete_group': {
            $gid=(int)($_POST['id_group']??0); if(!$gid) err('Missing group');
            $pdo->prepare("DELETE FROM `$t_groups` WHERE id_group=:g")->execute([':g'=>$gid]); ok();
        }
        case 'reorder_groups': {
            $arr=json_decode((string)($_POST['order']??'[]'),true)?:[]; $pdo->beginTransaction();
            $st=$pdo->prepare("UPDATE `$t_groups` SET sort_order=:s WHERE id_group=:g"); $i=1; foreach($arr as $row){ $gid=(int)($row['id_group']??0); $so=(int)($row['sort_order']??$i); $st->execute([':s'=>$so,':g'=>$gid]); $i++; }
            $pdo->commit(); ok();
        }
        case 'delete_requirement': {
            $rid=(int)($_POST['id_requirement']??0); if(!$rid) err('Missing requirement');
            $pdo->prepare("DELETE FROM `$t_require` WHERE id_requirement=:r")->execute([':r'=>$rid]); ok();
        }
        case 'save_requirements_bulk': {
            $gid=(int)($_POST['id_group']??0); if(!$gid) err('Missing group');
            $rows=json_decode((string)($_POST['requirements']??'[]'),true); if(!is_array($rows)) err('Invalid payload');
            $ins=$pdo->prepare("INSERT INTO `$t_require` (id_group,title,file_type,`required`,description,sort_order,active) VALUES (:g,:t,:ft,:rq,:ds,:so,:ac)");
            $upd=$pdo->prepare("UPDATE `$t_require` SET title=:t,file_type=:ft,`required`=:rq,description=:ds,sort_order=:so,active=:ac WHERE id_requirement=:r AND id_group=:g");
            $pdo->beginTransaction();
            foreach($rows as $r){
                $rid=(int)($r['id_requirement']??0); $t=trim((string)($r['title']??'')); if($t==='') $t='Untitled';
                $ft=in_array(($r['file_type']??'pdf'),['pdf','image','any'],true)?$r['file_type']:'pdf';
                $rq=(int)($r['required']??1); $ds=(string)($r['description']??''); $so=(int)($r['sort_order']??0); $ac=(int)($r['active']??1);
                if($rid>0) $upd->execute([':t'=>$t,':ft'=>$ft,':rq'=>$rq,':ds'=>$ds,':so'=>$so,':ac'=>$ac,':r'=>$rid,':g'=>$gid]);
                else       $ins->execute([':g'=>$gid,':t'=>$t,':ft'=>$ft,':rq'=>$rq,':ds'=>$ds,':so'=>$so,':ac'=>$ac]);
            }
            $pdo->commit(); ok();
        }
        case 'test_upload': {
            if(empty($_FILES['file']['tmp_name'])) err('No file'); $gid=(int)($_POST['id_group']??0); if(!$gid) err('Missing group');
            $base=__DIR__.'/../test_uploads'; if(!is_dir($base)) @mkdir($base,0775,true); if(!is_dir($base)) err('Cannot create test_uploads');
            $orig=$_FILES['file']['name']??'upload.bin'; $ext=strtolower(pathinfo($orig,PATHINFO_EXTENSION)); if(!in_array($ext,['pdf','jpg','jpeg','png','gif','webp'],true)) err('Not allowed type');
            $fname='gid'.$gid.'-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.'.$ext; $dest=$base.'/'.$fname;
            if(!move_uploaded_file($_FILES['file']['tmp_name'],$dest)) err('Move failed'); ok(['filename'=>$fname]);
        }
        default: err('Unknown action');
    }
}catch(Throwable $e){ err('Exception: '.$e->getMessage()); }
