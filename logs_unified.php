<?php
/**************************************************
 * modules/lrofileupload/admin/logs_unified.php
 * Unified viewer for LRO-SA module activity:
 *   - psfc_lrofileupload_action_logs
 *   - psfc_lrofileupload_admin_logs
 *   - psfc_lrofileupload_audit_log
 *   - psfc_lrofileupload_logs
 *   - psfc_lrofileupload_rejection_reason_logs
 * Tolerant to missing tables/columns. CSV export.
 **************************************************/
declare(strict_types=1);

ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

/* ---------- Bootstrap & auth ---------- */
$HERE = __DIR__;
$ROOT = realpath($HERE.'/../../..');
$bootA = $HERE.'/_bootstrap.php';
$bootB = $ROOT.'/config/config.inc.php';
if (is_file($bootA))      require_once $bootA;
elseif (is_file($bootB))  require_once $bootB;
else { http_response_code(500); exit('Bootstrap failed'); }

if (session_status()!==PHP_SESSION_ACTIVE) session_start();
if (function_exists('lro_require_admin')) {
  lro_require_admin(false);
} elseif (function_exists('require_admin_login')) {
  require_admin_login(false);
} elseif (empty($_SESSION['admin_id'])) {
  http_response_code(403); exit('Forbidden');
}
if (function_exists('require_cap')) {
  require_cap('can_view_uploads');
}

/* ---------- DB helpers ---------- */
$db     = Db::getInstance();
$prefix = _DB_PREFIX_;
$DEBUG  = (Tools::getValue('debug','0') === '1');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function val($k,$d=''){ return Tools::getValue($k,$d); }

function tableExists(Db $db, string $t): bool {
  $sql="SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".pSQL($t)."'";
  return (int)$db->getValue($sql)>0;
}
function columns(Db $db, string $t): array {
  if (!tableExists($db,$t)) return [];
  $rows=$db->executeS("SELECT COLUMN_NAME c FROM INFORMATION_SCHEMA.COLUMNS
                       WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".pSQL($t)."'")?:[];
  $map=[]; foreach($rows as $r){ $map[strtolower($r['c'])]=true; } return $map;
}

/* ---------- Known tables ---------- */
$T = [
  'uploads'   => $prefix.'lrofileupload_uploads',
  'groupsA'   => $prefix.'lrofileupload_groups',
  'groupsB'   => $prefix.'lrofileupload_product_groups',
  'admins'    => $prefix.'lrofileupload_admins',
  'action'    => $prefix.'lrofileupload_action_logs',
  'admin'     => $prefix.'lrofileupload_admin_logs',
  'audit'     => $prefix.'lrofileupload_audit_log',
  'uplog'     => $prefix.'lrofileupload_logs',
  'rejlog'    => $prefix.'lrofileupload_rejection_reason_logs',
];

$exists=[]; $cols=[];
foreach($T as $k=>$name){ $exists[$k]=tableExists($db,$name); $cols[$k]=$exists[$k]?columns($db,$name):[]; }

/* ---------- Groups label expression ---------- */
$grpTbl = $exists['groupsA'] ? $T['groupsA'] : ($exists['groupsB'] ? $T['groupsB'] : null);
$GROUP_LABEL='NULL'; $grpHasId=false;
if ($grpTbl){
  $gC=columns($db,$grpTbl); $grpHasId=isset($gC['id_group']);
  $pieces=[];
  foreach(['group_name','name','title','label'] as $c){ if(isset($gC[$c])) $pieces[]="g.`$c`"; }
  if ($grpHasId) $pieces[]="CONCAT('Group #', g.id_group)";
  if ($pieces) $GROUP_LABEL='COALESCE('.implode(',',$pieces).')';
  else $GROUP_LABEL=$grpHasId? "CONCAT('Group #', g.id_group)" : "'Group'";
}

/* ---------- Filters ---------- */
$adminId    = (int)val('admin_id',0);
$groupId    = (int)val('group_id',0);
$uploadId   = (int)val('upload_id',0);
$customerId = (int)val('customer_id',0);
$dateFrom   = trim((string)val('date_from',''));
$dateTo     = trim((string)val('date_to',''));
$q          = trim((string)val('q',''));
$page       = max(1,(int)val('page',1));
$perPage    = max(10,min(200,(int)val('per_page',50)));
$exportCsv  = (val('export')==='csv');

/* ---------- Filter dropdown data ---------- */
try { $admins = $db->executeS("SELECT admin_id, username FROM `{$T['admins']}` ORDER BY username") ?: []; }
catch(Throwable $e){ $admins=[]; }

$groups=[];
if ($grpTbl){
  $groups=$db->executeS("SELECT ".($grpHasId?"g.id_group":"0 AS id_group").",
                                {$GROUP_LABEL} AS group_name
                         FROM `{$grpTbl}` g
                         ORDER BY 2") ?: [];
}

/* ---------- Build UNION parts ---------- */
$parts=[];

/* A) action_logs */
if ($exists['action']) {
  $A = $cols['action'];
  $where=[];
  if ($adminId>0 && isset($A['admin_id'])) $where[]="al.admin_id=".(int)$adminId;
  if ($groupId>0 && isset($A['group_id'])) $where[]="al.group_id=".(int)$groupId;
  if ($uploadId>0 && isset($A['target_id']) && isset($A['target_type'])) $where[]="(al.target_type='upload' AND al.target_id=".(int)$uploadId.")";
  if ($dateFrom!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateFrom) && isset($A['created_at'])) $where[]="al.created_at>='".pSQL($dateFrom)." 00:00:00'";
  if ($dateTo!==''   && preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateTo)   && isset($A['created_at'])) $where[]="al.created_at<='".pSQL($dateTo)." 23:59:59'";
  if ($q!==''){
    $like="'%".pSQL($q,true)."%'";
    $segs=[]; if(isset($A['action_type']))$segs[]="al.action_type LIKE $like";
              if(isset($A['target_type']))$segs[]="al.target_type LIKE $like";
              if(isset($A['description']))$segs[]="al.description LIKE $like";
    if($segs)$where[]='('.implode(' OR ',$segs).')';
  }
  $sel_ts   = isset($A['created_at']) ? 'al.created_at' : 'NULL';
  $sel_aid  = isset($A['admin_id'])   ? 'al.admin_id'   : 'NULL';
  $sel_act  = isset($A['action_type'])? 'al.action_type': 'NULL';
  $sel_tty  = isset($A['target_type'])? 'al.target_type': 'NULL';
  $sel_tid  = isset($A['target_id'])  ? 'al.target_id'  : 'NULL';
  $sel_desc = isset($A['description'])? 'al.description': 'NULL';
  $sel_gid  = isset($A['group_id'])   ? 'al.group_id'   : 'NULL';
  $joinG = ($grpTbl && isset($A['group_id'])) ? "LEFT JOIN `{$grpTbl}` g ON g.id_group = al.group_id" : '';
  $selG = ($grpTbl && isset($A['group_id'])) ? "{$GROUP_LABEL} AS group_name" : "NULL AS group_name";

  $joinUp=''; $selUp="NULL AS customer_id, NULL AS file_name";
  if ($exists['uploads'] && isset($A['target_id']) && isset($A['target_type'])){
    $uC=$cols['uploads']; $uId=isset($uC['id_upload'])?'id_upload':(isset($uC['upload_id'])?'upload_id':(isset($uC['id'])?'id':null));
    if ($uId){
      $joinUp="LEFT JOIN `{$T['uploads']}` u ON (al.target_type='upload' AND al.target_id=u.`$uId`)";
      $uCust=isset($uC['id_customer'])?'id_customer':(isset($uC['customer_id'])?'customer_id':null);
      $uName=isset($uC['file_name'])?'file_name':(isset($uC['filename'])?'filename':(isset($uC['original_name'])?'original_name':null));
      $selUp=($uCust?"u.`$uCust`":"NULL")." AS customer_id, ".($uName?"u.`$uName`":"NULL")." AS file_name";
      if ($customerId>0 && $uCust) $where[]="u.`$uCust`=".(int)$customerId;
    }
  }
  $whereSQL=$where?('WHERE '.implode(' AND ',$where)):'';

  $parts[]="
    SELECT
      $sel_ts AS ts,
      $sel_aid AS admin_id,
      a.username AS admin_name,
      $sel_gid AS group_id,
      $selG,
      $sel_act AS action,
      $sel_tty AS target_type,
      $sel_tid AS target_id,
      $sel_desc AS details,
      $selUp,
      'action_logs' AS source
    FROM `{$T['action']}` al
    LEFT JOIN `{$T['admins']}` a ON a.admin_id=al.admin_id
    $joinG
    $joinUp
    $whereSQL
  ";
}

/* B) admin_logs */
if ($exists['admin']){
  $C=$cols['admin'];
  $where=[];
  if ($adminId>0 && isset($C['admin_id'])) $where[]="al2.admin_id=".(int)$adminId;
  if ($uploadId>0 && isset($C['target_id'])) $where[]="al2.target_id=".(int)$uploadId;
  if ($dateFrom!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateFrom) && (isset($C['created_at'])||isset($C['timestamp'])))
    $where[]="COALESCE(al2.created_at, al2.`timestamp`) >= '".pSQL($dateFrom)." 00:00:00'";
  if ($dateTo!==''   && preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateTo)   && (isset($C['created_at'])||isset($C['timestamp'])))
    $where[]="COALESCE(al2.created_at, al2.`timestamp`) <= '".pSQL($dateTo)." 23:59:59'";
  if ($q!==''){
    $like="'%".pSQL($q,true)."%'";
    $segs=[]; if(isset($C['action']))$segs[]="al2.action LIKE $like";
              if(isset($C['description']))$segs[]="al2.description LIKE $like";
              if(isset($C['admin_username']))$segs[]="al2.admin_username LIKE $like";
    if($segs)$where[]='('.implode(' OR ',$segs).')';
  }
  $whereSQL=$where?('WHERE '.implode(' AND ',$where)):'';

  $parts[]="
    SELECT
      COALESCE(al2.created_at, al2.`timestamp`) AS ts,
      al2.admin_id AS admin_id,
      al2.admin_username AS admin_name,
      NULL AS group_id,
      NULL AS group_name,
      al2.action AS action,
      'admin' AS target_type,
      al2.target_id AS target_id,
      al2.description AS details,
      NULL AS customer_id,
      NULL AS file_name,
      'admin_logs' AS source
    FROM `{$T['admin']}` al2
    $whereSQL
  ";
}

/* C) audit_log */
if ($exists['audit']){
  $D=$cols['audit'];
  $where=[];
  if ($adminId>0 && isset($D['admin_id'])) $where[]="aud.admin_id=".(int)$adminId;
  if ($uploadId>0 && isset($D['id_upload'])) $where[]="aud.id_upload=".(int)$uploadId;
  if ($dateFrom!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateFrom) && isset($D['ts'])) $where[]="aud.ts>='".pSQL($dateFrom)." 00:00:00'";
  if ($dateTo!==''   && preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateTo)   && isset($D['ts'])) $where[]="aud.ts<='".pSQL($dateTo)." 23:59:59'";
  if ($q!==''){
    $like="'%".pSQL($q,true)."%'";
    $segs=[]; if(isset($D['action']))$segs[]="aud.action LIKE $like";
              if(isset($D['ip']))    $segs[]="aud.ip LIKE $like";
              if(isset($D['ua']))    $segs[]="aud.ua LIKE $like";
              if(isset($D['meta']))  $segs[]="aud.meta LIKE $like";
    if($segs)$where[]='('.implode(' OR ',$segs).')';
  }
  $joinUp=''; $selCust='NULL AS customer_id'; $selFile='NULL AS file_name';
  if ($exists['uploads'] && isset($D['id_upload'])){
    $uC=$cols['uploads']; $uId=isset($uC['id_upload'])?'id_upload':(isset($uC['upload_id'])?'upload_id':(isset($uC['id'])?'id':null));
    if ($uId){
      $joinUp="LEFT JOIN `{$T['uploads']}` u ON u.`$uId`=aud.id_upload";
      $uCust=isset($uC['id_customer'])?'id_customer':(isset($uC['customer_id'])?'customer_id':null);
      $uName=isset($uC['file_name'])?'file_name':(isset($uC['filename'])?'filename':(isset($uC['original_name'])?'original_name':null));
      $selCust=$uCust? "u.`$uCust` AS customer_id":"NULL AS customer_id";
      $selFile=$uName? "u.`$uName` AS file_name":"NULL AS file_name";
      if ($customerId>0 && $uCust) $where[]="u.`$uCust`=".(int)$customerId;
    }
  }
  $whereSQL=$where?('WHERE '.implode(' AND ',$where)):'';

  $parts[]="
    SELECT
      aud.ts AS ts,
      aud.admin_id AS admin_id,
      a.username AS admin_name,
      NULL AS group_id,
      NULL AS group_name,
      aud.action AS action,
      'upload' AS target_type,
      aud.id_upload AS target_id,
      CONCAT_WS(' ', aud.ip, aud.ua) AS details,
      $selCust,
      $selFile,
      'audit_log' AS source
    FROM `{$T['audit']}` aud
    LEFT JOIN `{$T['admins']}` a ON a.admin_id=aud.admin_id
    $joinUp
    $whereSQL
  ";
}

/* D) upload event log */
if ($exists['uplog']){
  $U=$cols['uplog']; // id_log,id_customer,id_group,filename,file_path,file_size,file_type,upload_date,status
  $where=[];
  if ($groupId>0 && isset($U['id_group'])) $where[]="ul.id_group=".(int)$groupId;
  if ($customerId>0 && isset($U['id_customer'])) $where[]="ul.id_customer=".(int)$customerId;
  if ($dateFrom!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateFrom) && isset($U['upload_date'])) $where[]="ul.upload_date>='".pSQL($dateFrom)." 00:00:00'";
  if ($dateTo!==''   && preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateTo)   && isset($U['upload_date'])) $where[]="ul.upload_date<='".pSQL($dateTo)." 23:59:59'";
  if ($q!==''){
    $like="'%".pSQL($q,true)."%'";
    $segs=[]; if(isset($U['filename'])) $segs[]="ul.filename LIKE $like";
              if(isset($U['file_path']))$segs[]="ul.file_path LIKE $like";
              if(isset($U['status']))   $segs[]="ul.status LIKE $like";
    if($segs)$where[]='('.implode(' OR ',$segs).')';
  }
  $joinG = ($grpTbl && isset($U['id_group'])) ? "LEFT JOIN `{$grpTbl}` g ON g.id_group=ul.id_group" : '';
  $selG  = ($grpTbl && isset($U['id_group'])) ? "{$GROUP_LABEL} AS group_name" : "NULL AS group_name";
  $whereSQL=$where?('WHERE '.implode(' AND ',$where)):'';

  $parts[]="
    SELECT
      ul.upload_date AS ts,
      NULL AS admin_id,
      NULL AS admin_name,
      ".(isset($U['id_group'])?'ul.id_group':'NULL')." AS group_id,
      $selG,
      CONCAT('upload:', COALESCE(ul.status,'')) AS action,
      'upload_event' AS target_type,
      ul.id_log AS target_id,
      COALESCE(ul.file_type,'') AS details,
      ".(isset($U['id_customer'])?'ul.id_customer':'NULL')." AS customer_id,
      COALESCE(ul.filename, ul.file_path) AS file_name,
      'file_log' AS source
    FROM `{$T['uplog']}` ul
    $joinG
    $whereSQL
  ";
}

/* E) rejection_reason_logs (column-safe select) */
if ($exists['rejlog']){
  $R=$cols['rejlog']; // id_log, admin_id, action, reasons_text?, reason_text?, description?, id_reason, created_at
  $where=[];
  if ($adminId>0 && isset($R['admin_id'])) $where[]="rl.admin_id=".(int)$adminId;
  if ($dateFrom!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateFrom) && isset($R['created_at'])) $where[]="rl.created_at>='".pSQL($dateFrom)." 00:00:00'";
  if ($dateTo!==''   && preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateTo)   && isset($R['created_at'])) $where[]="rl.created_at<='".pSQL($dateTo)." 23:59:59'";
  if ($q!==''){
    $like="'%".pSQL($q,true)."%'";
    $segs=[]; if(isset($R['action']))$segs[]="rl.action LIKE $like";
              if(isset($R['reasons_text']))$segs[]="rl.reasons_text LIKE $like";
              if(isset($R['reason_text'])) $segs[]="rl.reason_text LIKE $like";
              if(isset($R['description'])) $segs[]="rl.description LIKE $like";
    if($segs)$where[]='('.implode(' OR ',$segs).')';
  }
  $whereSQL=$where?('WHERE '.implode(' AND ',$where)):'';

  // choose the existing detail column safely
  if     (isset($R['reasons_text'])) $selDetail = 'rl.reasons_text';
  elseif (isset($R['reason_text']))  $selDetail = 'rl.reason_text';
  elseif (isset($R['description']))  $selDetail = 'rl.description';
  else                               $selDetail = "NULL";

  $parts[]="
    SELECT
      ".(isset($R['created_at'])?'rl.created_at':'NULL')." AS ts,
      ".(isset($R['admin_id'])?'rl.admin_id':'NULL')." AS admin_id,
      a.username AS admin_name,
      NULL AS group_id,
      NULL AS group_name,
      ".(isset($R['action'])?'rl.action':"NULL")." AS action,
      'reject_reason' AS target_type,
      ".(isset($R['id_reason'])?'rl.id_reason':'NULL')." AS target_id,
      $selDetail AS details,
      NULL AS customer_id,
      NULL AS file_name,
      'rejection_reasons' AS source
    FROM `{$T['rejlog']}` rl
    LEFT JOIN `{$T['admins']}` a ON a.admin_id=rl.admin_id
    $whereSQL
  ";
}

/* ---------- Build union & execute ---------- */
if (!$parts){
  $union = "SELECT NULL ts,NULL admin_id,NULL admin_name,NULL group_id,NULL group_name,
                   NULL action,NULL target_type,NULL target_id,NULL details,NULL customer_id,
                   NULL file_name,'none' source WHERE 1=0";
} else {
  $union = implode(" UNION ALL ", array_map(fn($q)=>"($q)", $parts));
}

try {
  $total  = (int)$db->getValue("SELECT COUNT(*) FROM ($union) T");
  $page   = max(1,$page);
  $offset = ($page-1)*$perPage;
  $rows   = $db->executeS("SELECT * FROM ($union) T ORDER BY ts DESC LIMIT ".(int)$offset.", ".(int)$perPage) ?: [];
} catch (Throwable $e) {
  if ($DEBUG) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "SQL/Execution error:\n\n".$e->getMessage()."\n";
    echo "\n---\nUNION SQL used:\n$union\n";
    exit;
  }
  throw $e;
}

/* ---------- CSV export ---------- */
if ($exportCsv){
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=unified_logs.csv');
  $out=fopen('php://output','w');
  fputcsv($out,['Timestamp','Admin','Group','Action','Target','File','Customer','Details','Source']);
  foreach($rows as $r){
    $target = trim((string)($r['target_type']??'').' #'.(string)($r['target_id']??''));
    fputcsv($out,[
      (string)($r['ts']??''),(string)($r['admin_name']??''),(string)($r['group_name']??''),
      (string)($r['action']??''),$target,(string)($r['file_name']??''),(string)($r['customer_id']??''),
      (string)($r['details']??''),(string)($r['source']??''),
    ]);
  }
  fclose($out); exit;
}

/* ---------- UI ---------- */
$qsBase = $_GET; unset($qsBase['page'],$qsBase['export']);
function linkWithPage($base,$p){ $base['page']=$p; return '?'.http_build_query($base); }
$totalPages = max(1,(int)ceil($total/$perPage));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Unified File Activity Logs</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:#f7f9fc}
    .page-title{font-weight:700}
    .nowrap{white-space:nowrap}
  </style>
</head>
<body>
<div class="container py-4">
  <?php if (file_exists(__DIR__.'/nav.php')) include __DIR__.'/nav.php'; ?>

  <h3 class="page-title mb-3">Unified File Activity Logs</h3>

  <form method="get" class="row g-3 mb-4">
    <div class="col-md-2">
      <label class="form-label">Admin</label>
      <select name="admin_id" class="form-select">
        <option value="0">All</option>
        <?php foreach($admins as $a): ?>
          <option value="<?= (int)$a['admin_id'] ?>" <?= $adminId==(int)$a['admin_id']?'selected':'' ?>><?= h($a['username']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">Group</label>
      <select name="group_id" class="form-select">
        <option value="0">All</option>
        <?php foreach($groups as $g): ?>
          <option value="<?= (int)$g['id_group'] ?>" <?= $groupId==(int)$g['id_group']?'selected':'' ?>><?= h($g['group_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">Upload ID</label>
      <input type="number" name="upload_id" class="form-control" value="<?= $uploadId?:'' ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label">Customer ID</label>
      <input type="number" name="customer_id" class="form-control" value="<?= $customerId?:'' ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label">From</label>
      <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label">To</label>
      <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>">
    </div>
    <div class="col-md-8">
      <label class="form-label">Search</label>
      <input type="text" name="q" class="form-control" placeholder="keyword in action / details / file" value="<?= h($q) ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label">Per Page</label>
      <input type="number" min="10" max="200" name="per_page" class="form-control" value="<?= (int)$perPage ?>">
    </div>
    <div class="col-md-2 d-flex align-items-end gap-2">
      <button class="btn btn-primary w-100">Apply</button>
      <?php $tmp=$qsBase; $tmp['export']='csv'; ?>
      <a class="btn btn-success" href="<?= h('?'.http_build_query($tmp)) ?>">Export CSV</a>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-sm table-striped table-hover align-middle">
      <thead class="table-dark">
        <tr>
          <th class="nowrap">Timestamp</th>
          <th>Admin</th>
          <th>Group</th>
          <th>Action</th>
          <th>Target</th>
          <th>File</th>
          <th>Customer</th>
          <th>Details</th>
          <th>Source</th>
        </tr>
      </thead>
      <tbody>
        <?php if(!$rows): ?>
          <tr><td colspan="9" class="text-center text-muted">No log entries found.</td></tr>
        <?php else: foreach($rows as $r): ?>
          <tr>
            <td class="nowrap"><?= h($r['ts']??'') ?></td>
            <td><?= h($r['admin_name']??'') ?></td>
            <td><?= h($r['group_name']??'') ?></td>
            <td><?= h($r['action']??'') ?></td>
            <td><?= h(trim(($r['target_type']??'').' #'.($r['target_id']??''))) ?></td>
            <td><?= h($r['file_name']??'') ?></td>
            <td><?= h((string)($r['customer_id']??'')) ?></td>
            <td class="text-break"><?= nl2br(h($r['details']??'')) ?></td>
            <td><span class="badge bg-secondary"><?= h($r['source']??'') ?></span></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages>1): ?>
    <nav class="mt-3">
      <ul class="pagination">
        <li class="page-item <?= $page<=1?'disabled':'' ?>">
          <a class="page-link" href="<?= h(linkWithPage($qsBase,max(1,$page-1))) ?>">Prev</a>
        </li>
        <li class="page-item disabled"><span class="page-link">Page <?= (int)$page ?> / <?= (int)$totalPages ?></span></li>
        <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>">
          <a class="page-link" href="<?= h(linkWithPage($qsBase,min($totalPages,$page+1))) ?>">Next</a>
        </li>
      </ul>
    </nav>
  <?php endif; ?>
</div>
</body>
</html>
