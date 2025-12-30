<?php
/**************************************************
 * Manage File Groups (UI) — PS 8.x / PHP 8.x
 **************************************************/
declare(strict_types=1);
ini_set('display_errors','1'); ini_set('display_startup_errors','1'); error_reporting(E_ALL);

/* ---- bootstrap ---- */
(function () {
    $dir = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        if (file_exists($dir.'/config/config.inc.php') && file_exists($dir.'/init.php')) {
            require_once $dir.'/config/config.inc.php'; require_once $dir.'/init.php'; return;
        }
        $dir = dirname($dir);
    }
    $root = dirname(__DIR__, 3);
    if (file_exists($root.'/config/config.inc.php') && file_exists($root.'/init.php')) {
        require_once $root.'/config/config.inc.php'; require_once $root.'/init.php'; return;
    }
    http_response_code(500); exit('Cannot bootstrap PrestaShop');
})();

if (session_status() === PHP_SESSION_NONE) session_start();

/* ---- secure PDO from Db ---- */
function lro_ps_pdo(): PDO {
    $db = Db::getInstance();
    foreach (['getLink','getPDO'] as $m) {
        if (method_exists($db,$m)) {
            try { $rm = new ReflectionMethod($db,$m); $rm->setAccessible(true); $pdo=$rm->invoke($db); if($pdo instanceof PDO) return $pdo; } catch(Throwable) {}
        }
    }
    try { $ro=new ReflectionObject($db); if($ro->hasProperty('link')){ $p=$ro->getProperty('link'); $p->setAccessible(true); $pdo=$p->getValue($db); if($pdo instanceof PDO) return $pdo; } } catch(Throwable) {}
    throw new RuntimeException('PDO handle not reachable');
}

/* ---- minimal auth ---- */
function lro_require_admin(): void {
    if (is_file(__DIR__.'/auth.php')) { require_once __DIR__.'/auth.php'; if (function_exists('require_admin_login')) { require_admin_login(false); return; } }
    if (empty($_SESSION['admin_id'])) { http_response_code(403); exit('Forbidden'); }
}
lro_require_admin();

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf_token'];

$pdo    = lro_ps_pdo();
$prefix = defined('_DB_PREFIX_') ? _DB_PREFIX_ : 'ps_';
$t_groups   = $prefix.'lrofileupload_groups';
$t_grpprods = $prefix.'lrofileupload_group_products';
$t_require  = $prefix.'lrofileupload_requirements';

/* ---- schema helpers ---- */
function tExists(PDO $pdo,string $t):bool{ try{$pdo->query("SELECT 1 FROM `$t` LIMIT 1")->fetch(); return true;}catch(Throwable){return false;}}
function cExists(PDO $pdo,string $t,string $c):bool{ $s=$pdo->prepare("SHOW COLUMNS FROM `$t` LIKE :c"); $s->execute([':c'=>$c]); return (bool)$s->fetch(); }
function tryRename(PDO $pdo,string $t,string $from,string $to,string $def):void{ $s=$pdo->prepare("SHOW COLUMNS FROM `$t` LIKE :c"); $s->execute([':c'=>$from]); if($s->fetch()) $pdo->exec("ALTER TABLE `$t` CHANGE `$from` `$to` $def"); }

function ensure_groups(PDO $pdo,string $t):void{
    if(!tExists($pdo,$t)){
        $pdo->exec("CREATE TABLE `$t`(
            id_group INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            unlock_by_purchase TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if(!cExists($pdo,$t,'name')){ tryRename($pdo,$t,'group_name','name','VARCHAR(255) NOT NULL'); tryRename($pdo,$t,'title','name','VARCHAR(255) NOT NULL'); if(!cExists($pdo,$t,'name')) $pdo->exec("ALTER TABLE `$t` ADD name VARCHAR(255) NOT NULL AFTER id_group");}
    if(!cExists($pdo,$t,'description')){ tryRename($pdo,$t,'desc','description','TEXT NULL'); if(!cExists($pdo,$t,'description')) $pdo->exec("ALTER TABLE `$t` ADD description TEXT NULL AFTER name");}
    if(!cExists($pdo,$t,'active')){ tryRename($pdo,$t,'is_active','active','TINYINT(1) NOT NULL DEFAULT 1'); if(!cExists($pdo,$t,'active')) $pdo->exec("ALTER TABLE `$t` ADD active TINYINT(1) NOT NULL DEFAULT 1 AFTER description");}
    if(!cExists($pdo,$t,'sort_order')) $pdo->exec("ALTER TABLE `$t` ADD sort_order INT NOT NULL DEFAULT 0 AFTER active");
    if(!cExists($pdo,$t,'unlock_by_purchase')) $pdo->exec("ALTER TABLE `$t` ADD unlock_by_purchase TINYINT(1) NOT NULL DEFAULT 1 AFTER sort_order");
}
function ensure_group_products(PDO $pdo,string $t):void{
    if(!tExists($pdo,$t)){
        $pdo->exec("CREATE TABLE `$t`(
            id_group INT UNSIGNED NOT NULL,
            id_product INT UNSIGNED NOT NULL,
            PRIMARY KEY(id_group,id_product),
            KEY idx_gp_pid(id_product)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
function ensure_requirements(PDO $pdo,string $t):void{
    if(!tExists($pdo,$t)){
        $pdo->exec("CREATE TABLE `$t`(
            id_requirement INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            id_group INT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            file_type ENUM('pdf','image','any') NOT NULL DEFAULT 'pdf',
            required TINYINT(1) NOT NULL DEFAULT 1,
            description TEXT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            KEY idx_req_group(id_group)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if(!cExists($pdo,$t,'title')){ tryRename($pdo,$t,'name','title','VARCHAR(255) NOT NULL'); tryRename($pdo,$t,'label','title','VARCHAR(255) NOT NULL'); tryRename($pdo,$t,'req_title','title','VARCHAR(255) NOT NULL'); if(!cExists($pdo,$t,'title')) $pdo->exec("ALTER TABLE `$t` ADD title VARCHAR(255) NOT NULL AFTER id_group");}
    if(!cExists($pdo,$t,'file_type')) $pdo->exec("ALTER TABLE `$t` ADD file_type ENUM('pdf','image','any') NOT NULL DEFAULT 'pdf' AFTER title");
    if(!cExists($pdo,$t,'required')){ tryRename($pdo,$t,'is_required','required','TINYINT(1) NOT NULL DEFAULT 1'); if(!cExists($pdo,$t,'required')) $pdo->exec("ALTER TABLE `$t` ADD required TINYINT(1) NOT NULL DEFAULT 1 AFTER file_type"); }
    if(!cExists($pdo,$t,'description')){ tryRename($pdo,$t,'desc','description','TEXT NULL'); if(!cExists($pdo,$t,'description')) $pdo->exec("ALTER TABLE `$t` ADD description TEXT NULL AFTER required");}
    if(!cExists($pdo,$t,'sort_order')) $pdo->exec("ALTER TABLE `$t` ADD sort_order INT NOT NULL DEFAULT 0 AFTER description");
    if(!cExists($pdo,$t,'active')){ tryRename($pdo,$t,'is_active','active','TINYINT(1) NOT NULL DEFAULT 1'); if(!cExists($pdo,$t,'active')) $pdo->exec("ALTER TABLE `$t` ADD active TINYINT(1) NOT NULL DEFAULT 1 AFTER sort_order");}
}
ensure_groups($pdo,$t_groups); ensure_group_products($pdo,$t_grpprods); ensure_requirements($pdo,$t_require);

/* ---- data ---- */
$groups = $pdo->query("SELECT * FROM `$t_groups` ORDER BY sort_order, id_group")->fetchAll(PDO::FETCH_ASSOC);
$reqsAll = $pdo->query("SELECT * FROM `$t_require` ORDER BY id_group, sort_order, id_requirement")->fetchAll(PDO::FETCH_ASSOC);
$reqsByGroup = []; foreach ($reqsAll as $r) { $reqsByGroup[(int)$r['id_group']][] = $r; }

$groupProducts = [];
if ($groups) {
    $ids = implode(',', array_map('intval', array_column($groups,'id_group')));
    if ($ids) {
        $gp = $pdo->query("SELECT id_group,id_product FROM `$t_grpprods` WHERE id_group IN ($ids)")->fetchAll(PDO::FETCH_ASSOC);
        $langId = (int)Context::getContext()->language->id;
        $pname = $prefix.'product_lang';
        $prodIds = array_unique(array_map(fn($x)=>(int)$x['id_product'],$gp));
        $names = [];
        if ($prodIds) {
            $plist = implode(',',$prodIds);
            foreach ($pdo->query("SELECT id_product,name FROM `$pname` WHERE id_lang=$langId AND id_product IN ($plist)")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $names[(int)$row['id_product']] = $row['name'] ?: ('#'.$row['id_product']);
            }
        }
        foreach ($gp as $row) $groupProducts[(int)$row['id_group']][] = ['id'=>(int)$row['id_product'],'name'=>$names[(int)$row['id_product']] ?? ('#'.$row['id_product'])];
    }
}
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manage File Groups</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body{background:#f7fbff} .card{border-radius:1rem; box-shadow:0 4px 18px rgba(13,110,253,.08)} .card-header{background:linear-gradient(90deg,#0d6efd,#4ea1ff); color:#fff; border-radius:1rem 1rem 0 0}
.badge-product{background:#e9f2ff; color:#0d6efd; border:1px solid #cce1ff; margin:2px} .draggable-handle{cursor:grab}
.select2-container .select2-selection--single{height:38px}
</style></head><body>
<nav class="navbar navbar-dark mb-4" style="background:#0d6efd"><div class="container-fluid">
  <span class="navbar-brand"><i class="bi bi-diagram-3"></i> LRO File Upload Admin</span>
  <div><?php if (is_file(__DIR__.'/nav.php')) include __DIR__.'/nav.php'; ?></div>
</div></nav>

<div class="container mb-4">
  <div class="row g-4">
    <div class="col-12 col-lg-4">
      <div class="card">
        <div class="card-header"><i class="bi bi-plus-circle"></i> Create New Group</div>
        <div class="card-body">
          <form id="formCreateGroup" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF); ?>">
            <div class="col-12"><label class="form-label">Group name</label><input class="form-control" name="name" required maxlength="255"></div>
            <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="2"></textarea></div>
            <div class="col-6"><label class="form-label">Active</label><select class="form-select" name="active"><option value="1" selected>Yes</option><option value="0">No</option></select></div>
            <div class="col-6"><label class="form-label">Unlock by Purchase</label><select class="form-select" name="unlock_by_purchase"><option value="1" selected>Yes</option><option value="0">No (manual)</option></select></div>
            <div class="col-12"><button class="btn btn-primary w-100" type="submit">Create Group</button></div>
          </form>
        </div>
      </div>
    </div>
    <div class="col-12 col-lg-8">
      <div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>Drag cards to reorder groups (saves <code>sort_order</code>). Assign products; uploads unlock for buyers when <b>Unlock by Purchase</b> is enabled. Manage requirements inline; FO honors <code>sort_order</code>.</div>
    </div>
  </div>
</div>

<div class="container" id="groupsList">
  <div class="row g-4">
    <?php foreach ($groups as $g): $gid=(int)$g['id_group']; $prods=$groupProducts[$gid]??[]; $reqs=$reqsByGroup[$gid]??[]; ?>
    <div class="col-12" data-group-id="<?php echo $gid; ?>">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <div class="d-flex align-items-center">
            <i class="bi bi-grip-vertical draggable-handle me-2"></i>
            <input class="form-control form-control-sm me-2" style="max-width:320px" value="<?php echo htmlspecialchars($g['name']); ?>" data-field="name" data-group-id="<?php echo $gid; ?>">
            <span class="badge bg-light text-dark">#<?php echo $gid; ?></span>
          </div>
          <div class="d-flex align-items-center gap-2">
            <select class="form-select form-select-sm" style="width:150px" data-field="active" data-group-id="<?php echo $gid; ?>">
              <option value="1" <?php echo $g['active']?'selected':''; ?>>Active</option>
              <option value="0" <?php echo !$g['active']?'selected':''; ?>>Inactive</option>
            </select>
            <select class="form-select form-select-sm" style="width:200px" data-field="unlock_by_purchase" data-group-id="<?php echo $gid; ?>">
              <option value="1" <?php echo $g['unlock_by_purchase']?'selected':''; ?>>Unlock by Purchase</option>
              <option value="0" <?php echo !$g['unlock_by_purchase']?'selected':''; ?>>Manual Unlock Only</option>
            </select>
            <button class="btn btn-sm btn-outline-danger" data-action="delete-group" data-group-id="<?php echo $gid; ?>"><i class="bi bi-trash"></i></button>
          </div>
        </div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea class="form-control" rows="2" data-field="description" data-group-id="<?php echo $gid; ?>"><?php echo htmlspecialchars((string)$g['description']); ?></textarea>
          </div>

          <div class="mb-3">
            <label class="form-label">Assigned Products</label>
            <div class="d-flex align-items-center gap-2 mb-2">
              <select class="form-select form-select-sm js-product-select" style="min-width:320px" data-group-id="<?php echo $gid; ?>"></select>
              <button class="btn btn-sm btn-primary" data-action="assign-product" data-group-id="<?php echo $gid; ?>"><i class="bi bi-plus-circle"></i> Add</button>
            </div>
            <div class="d-flex flex-wrap" id="group-products-<?php echo $gid; ?>">
              <?php foreach($prods as $p): ?>
              <span class="badge badge-product" data-product-id="<?php echo (int)$p['id']; ?>">
                <i class="bi bi-box-seam me-1"></i><?php echo htmlspecialchars($p['name']); ?>
                <a href="#" class="ms-2 text-danger" data-action="unassign-product" data-group-id="<?php echo $gid; ?>" data-product-id="<?php echo (int)$p['id']; ?>"><i class="bi bi-x-circle"></i></a>
              </span>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="mb-3">
            <div class="d-flex justify-content-between align-items-center">
              <label class="form-label mb-0">Document Requirements</label>
              <button class="btn btn-sm btn-outline-secondary" data-action="add-requirement" data-group-id="<?php echo $gid; ?>"><i class="bi bi-file-earmark-plus"></i> Add Requirement</button>
            </div>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-2">
                <thead class="table-light"><tr><th>Title</th><th>Type</th><th>Required</th><th>Description</th><th>Sort</th><th>Active</th><th></th></tr></thead>
                <tbody id="req-body-<?php echo $gid; ?>">
                  <?php foreach($reqs as $r): ?>
                  <tr class="req-row" data-requirement-id="<?php echo (int)$r['id_requirement']; ?>">
                    <td><input class="form-control form-control-sm" data-field="title" value="<?php echo htmlspecialchars($r['title']??''); ?>"></td>
                    <td><select class="form-select form-select-sm" data-field="file_type"><?php $ft=$r['file_type']??'pdf'; ?>
                        <option value="pdf"   <?php echo $ft==='pdf'?'selected':''; ?>>PDF</option>
                        <option value="image" <?php echo $ft==='image'?'selected':''; ?>>Image</option>
                        <option value="any"   <?php echo $ft==='any'?'selected':''; ?>>Any</option>
                    </select></td>
                    <td><select class="form-select form-select-sm" data-field="required"><?php $rq=(int)($r['required']??1); ?>
                        <option value="1" <?php echo $rq?'selected':''; ?>>Yes</option>
                        <option value="0" <?php echo !$rq?'selected':''; ?>>No</option>
                    </select></td>
                    <td><input class="form-control form-control-sm" data-field="description" value="<?php echo htmlspecialchars((string)($r['description']??'')); ?>"></td>
                    <td><input type="number" class="form-control form-control-sm" data-field="sort_order" value="<?php echo (int)($r['sort_order']??0); ?>"></td>
                    <td><select class="form-select form-select-sm" data-field="active"><?php $ac=(int)($r['active']??1); ?>
                        <option value="1" <?php echo $ac?'selected':''; ?>>Yes</option>
                        <option value="0" <?php echo !$ac?'selected':''; ?>>No</option>
                    </select></td>
                    <td class="text-end"><button class="btn btn-sm btn-outline-danger" data-action="delete-requirement"><i class="bi bi-trash"></i></button></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <div class="text-end"><button class="btn btn-success btn-sm" data-action="save-requirements" data-group-id="<?php echo $gid; ?>"><i class="bi bi-save2"></i> Save All Requirements</button></div>
          </div>

          <hr>
          <button class="btn btn-outline-primary btn-sm" data-action="open-test-upload" data-group-id="<?php echo $gid; ?>"><i class="bi bi-upload"></i> Test Upload (sandbox)</button>
          <small class="text-muted ms-2">Saves under <code>/modules/lrofileupload/test_uploads/</code></small>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Test Upload Modal -->
<div class="modal fade" id="testUploadModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="formTestUpload" enctype="multipart/form-data">
      <div class="modal-header"><h5 class="modal-title"><i class="bi bi-upload"></i> Test Upload</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF); ?>">
        <input type="hidden" name="action" value="test_upload">
        <input type="hidden" name="id_group" id="testUploadGroupId">
        <div class="mb-3"><label class="form-label">File</label><input type="file" class="form-control" name="file" required></div>
        <div class="alert alert-info">Demo-only sandbox to validate allowed types.</div>
      </div>
      <div class="modal-footer"><button class="btn btn-primary" type="submit">Upload</button></div>
    </form>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
const AJAX_URL='ajax_manage_file_groups.php', CSRF='<?php echo $CSRF; ?>';

$('.js-product-select').each(function(){ $(this).select2({ width:'resolve', placeholder:'Search product…',
  ajax:{ url:AJAX_URL, dataType:'json', delay:250, data:p=>({action:'search_products',q:p.term||''}),
        processResults:data=>({results:data.items||[]}) }});
});
$(document).on('click','[data-action="assign-product"]',function(e){
  e.preventDefault(); const gid=$(this).data('group-id'); const $s=$('.js-product-select[data-group-id="'+gid+'"]'); const pid=$s.val(); const text=$s.find('option:selected').text();
  if(!pid) return; $.post(AJAX_URL,{action:'assign_product',csrf_token:CSRF,id_group:gid,id_product:pid},r=>{ if(r.success){ $('#group-products-'+gid).append(`<span class="badge badge-product" data-product-id="${pid}"><i class="bi bi-box-seam me-1"></i>${$('<div>').text(text).html()} <a href="#" class="ms-2 text-danger" data-action="unassign-product" data-group-id="${gid}" data-product-id="${pid}"><i class="bi bi-x-circle"></i></a></span>`); $s.val(null).trigger('change'); } else alert(r.message||'Failed');},'json');
});
$(document).on('click','[data-action="unassign-product"]',function(e){ e.preventDefault(); const gid=$(this).data('group-id'), pid=$(this).data('product-id');
  $.post(AJAX_URL,{action:'unassign_product',csrf_token:CSRF,id_group:gid,id_product:pid},r=>{ if(r.success) $(`#group-products-${gid} [data-product-id="${pid}"]`).remove(); else alert(r.message||'Failed');},'json'); });

$('[data-field][data-group-id]').on('change',function(){ $.post(AJAX_URL,{action:'update_group_field',csrf_token:CSRF,id_group:$(this).data('group-id'),field:$(this).data('field'),value:$(this).val()}); });

$(document).on('click','[data-action="delete-group"]',function(){ if(!confirm('Delete this group and all of its requirements?')) return; const gid=$(this).data('group-id');
  $.post(AJAX_URL,{action:'delete_group',csrf_token:CSRF,id_group:gid},r=>{ if(r.success) $(`[data-group-id="${gid}"]`).closest('.col-12').remove(); else alert(r.message||'Failed');},'json'); });

$(document).on('click','[data-action="add-requirement"]',function(){ const gid=$(this).data('group-id'); $('#req-body-'+gid).append(`
<tr class="req-row" data-requirement-id="0">
  <td><input class="form-control form-control-sm" data-field="title" placeholder="Title"></td>
  <td><select class="form-select form-select-sm" data-field="file_type"><option value="pdf">PDF</option><option value="image">Image</option><option value="any">Any</option></select></td>
  <td><select class="form-select form-select-sm" data-field="required"><option value="1" selected>Yes</option><option value="0">No</option></select></td>
  <td><input class="form-control form-control-sm" data-field="description" placeholder="Description"></td>
  <td><input type="number" class="form-control form-control-sm" data-field="sort_order" value="0"></td>
  <td><select class="form-select form-select-sm" data-field="active"><option value="1" selected>Yes</option><option value="0">No</option></select></td>
  <td class="text-end"><button class="btn btn-sm btn-outline-danger" data-action="delete-requirement"><i class="bi bi-trash"></i></button></td>
</tr>`); });

$(document).on('click','[data-action="delete-requirement"]',function(e){ e.preventDefault(); const $tr=$(this).closest('tr.req-row'); const rid=parseInt($tr.data('requirement-id'),10)||0;
  if(rid===0){ $tr.remove(); return; } if(!confirm('Delete this requirement?')) return;
  $.post(AJAX_URL,{action:'delete_requirement',csrf_token:CSRF,id_requirement:rid},r=>{ if(r.success)$tr.remove(); else alert(r.message||'Failed');},'json'); });

$(document).on('click','[data-action="save-requirements"]',function(){ const gid=$(this).data('group-id'); const rows=[];
  $('#req-body-'+gid+' tr.req-row').each(function(){ const rid=parseInt($(this).data('requirement-id'),10)||0; const row={id_requirement:rid}; $(this).find('[data-field]').each(function(){ row[$(this).data('field')]=$(this).val(); }); rows.push(row); });
  $.post(AJAX_URL,{action:'save_requirements_bulk',csrf_token:CSRF,id_group:gid,requirements:JSON.stringify(rows)},r=>{ if(r.success) location.reload(); else alert(r.message||'Failed');},'json'); });

$('#formCreateGroup').on('submit',function(e){ e.preventDefault(); const data=$(this).serializeArray(); data.push({name:'action',value:'create_group'});
  $.post(AJAX_URL,data,r=>{ if(r.success) location.reload(); else alert(r.message||'Failed'); },'json'); });

Sortable.create(document.querySelector('#groupsList .row'),{ handle:'.draggable-handle', animation:150, onEnd: function(){
  const order=[]; $('#groupsList [data-group-id]').each(function(i){ order.push({id_group:$(this).data('group-id'),sort_order:i+1}); });
  $.post(AJAX_URL,{action:'reorder_groups',csrf_token:CSRF,order:JSON.stringify(order)});
}});

const testModal=new bootstrap.Modal(document.getElementById('testUploadModal'));
$(document).on('click','[data-action="open-test-upload"]',function(){ $('#testUploadGroupId').val($(this).data('group-id')); $('#formTestUpload')[0].reset(); testModal.show(); });
$('#formTestUpload').on('submit',function(e){ e.preventDefault(); const fd=new FormData(this);
  $.ajax({url:AJAX_URL,method:'POST',data:fd,contentType:false,processData:false,dataType:'json',success:r=>{ if(r.success){ alert('Uploaded: '+(r.filename||'ok')); testModal.hide(); } else alert(r.message||'Failed'); }});
});
</script>
</body></html>
