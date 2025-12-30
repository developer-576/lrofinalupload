<?php
/**************************************************
 * modules/lrofileupload/admin/view_uploads.php
 * Grouped (by customer) list with:
 *  - Embedded preview:
 *      * PDFs -> PDF.js (pdf_viewer.php)
 *      * Images -> <img> (served by serve_file.php)
 *  - Approve / Reject with preset reasons
 *  - Search + pending-only filter
 *  - File column shows: filename only + Group + Requirement
 *  - Requirement pulled from psfc_lrofileupload_requirements (id_requirement -> title)
 **************************************************/
declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors','1');
ini_set('display_startup_errors','1');

/* ---------- PS bootstrap ---------- */
$HERE = __DIR__;
$ROOT = realpath($HERE.'/../../..'); // /modules/lrofileupload/admin -> shop root
$bootA = $HERE.'/_bootstrap.php';
$bootB = $ROOT.'/config/config.inc.php';
if (is_file($bootA))      require_once $bootA;
elseif (is_file($bootB))  require_once $bootB;
else { http_response_code(500); exit('Failed to bootstrap PrestaShop.'); }

if (session_status()===PHP_SESSION_NONE) session_start();

/* ---------- Admin guard ---------- */
if (function_exists('lro_require_admin')) {
  lro_require_admin(false);
} elseif (function_exists('require_admin_login')) {
  require_admin_login(false);
} elseif (empty($_SESSION['admin_id'])) {
  http_response_code(403); exit('Forbidden');
}

/* ---------- Helpers ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function tableExists(Db $db, string $table): bool {
  $q = "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '".pSQL($table)."'";
  return (int)$db->getValue($q) > 0;
}
function tableCols(Db $db, string $table): array {
  if (!tableExists($db,$table)) return [];
  $rows = $db->executeS("SELECT COLUMN_NAME AS c FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '".pSQL($table)."'") ?: [];
  $out=[]; foreach ($rows as $r) $out[strtolower((string)$r['c'])]=true; return $out;
}
function firstExisting(array $cols, array $opts, ?string $fallback=null): ?string {
  foreach ($opts as $o){ if(isset($cols[strtolower($o)])) return $o; } return $fallback;
}
function stBadge(string $s): string {
  $s=strtolower(trim($s));
  return $s==='approved' ? 'success'
       : ($s==='rejected' ? 'danger'
       : ($s==='pending'||$s===''?'secondary':'info'));
}
function extOf(string $pathOrName): string {
  return strtolower((string)pathinfo($pathOrName, PATHINFO_EXTENSION));
}
function isPdfExt(string $ext): bool {
  return $ext === 'pdf';
}
function isImageExt(string $ext): bool {
  return in_array($ext, ['jpg','jpeg','jpe','png','gif','webp','bmp','tif','tiff','svg'], true);
}

/* Load group id -> name map from whichever groups table exists */
function loadGroupNames(Db $db, string $prefix): array {
  $candidates = [
    $prefix.'lrofileupload_file_groups',
    $prefix.'lrofileupload_groups',
    $prefix.'lrofileupload_product_groups',
  ];
  foreach ($candidates as $tbl) {
    if (!tableExists($db, $tbl)) continue;
    $cols = tableCols($db, $tbl);
    $idCol   = firstExisting($cols, ['id_group','group_id','gid','id'], null);
    $nameCol = firstExisting($cols, ['group_name','name','title','label'], null);
    if (!$idCol || !$nameCol) continue;
    $rows = $db->executeS("SELECT `".bqSQL($idCol)."` AS i, `".bqSQL($nameCol)."` AS n FROM `".bqSQL($tbl)."`") ?: [];
    $map = [];
    foreach ($rows as $r) { $map[(int)$r['i']] = (string)$r['n']; }
    if ($map) return $map;
  }
  return [];
}

/* Try detect requirements table + columns */
function detectRequirements(Db $db, string $prefix): ?array {
  $tbl = $prefix.'lrofileupload_requirements';
  if (!tableExists($db, $tbl)) return null;
  $cols = tableCols($db, $tbl);
  $idReq = firstExisting($cols, ['id_requirement','requirement_id','id'], null);
  $title = firstExisting($cols, ['title','name','label'], null);
  $gid   = firstExisting($cols, ['id_group','group_id','gid'], null);
  if (!$idReq || !$title) return null;
  return ['table'=>$tbl,'id_requirement'=>$idReq,'title'=>$title,'id_group'=>$gid];
}

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf_token'];

/* ---------- URLs ---------- */
$base       = rtrim(Tools::getShopDomainSsl(true), '/') . rtrim(__PS_BASE_URI__ ?? '/', '/');
$serveUrl   = $base.'/modules/lrofileupload/admin/serve_file.php';
$pdfViewer  = $base.'/modules/lrofileupload/admin/pdf_viewer.php'; // wraps PDF.js
$approveUrl = $base.'/modules/lrofileupload/admin/approve_file.php';
$rejectUrl  = $base.'/modules/lrofileupload/admin/reject_file.php';

/* ---------- Data ---------- */
$db     = Db::getInstance();
$prefix = _DB_PREFIX_;
$tbl    = $prefix.'lrofileupload_uploads';

$groups=[];          // UI accordion (customers)
$totalRows=0;

$groupNameById = loadGroupNames($db, $prefix);
$reqMeta       = detectRequirements($db, $prefix); // ['table','id_requirement','title','id_group'] or null

if (tableExists($db,$tbl)) {
  $cols = tableCols($db,$tbl);

  $c_id   = firstExisting($cols,['id_upload','file_id','id','upload_id'],null);
  $c_name = firstExisting($cols,['file_name','filename','original_name','name'],'file_name');
  $c_orig = firstExisting($cols,['original_name','orig_name','client_name'], null);
  $c_st   = firstExisting($cols,['status','state'],'status');
  $c_ts   = firstExisting($cols,['uploaded_at','date_uploaded','date_add','created_at','created'],null);
  $c_cid  = firstExisting($cols,['id_customer','customer_id','customer'],'id_customer');
  $c_gid  = firstExisting($cols,['id_group','group_id','gid'], null);
  $c_reqid= firstExisting($cols,['id_requirement','requirement_id','rid'], null);
  $c_req  = firstExisting($cols,['requirement_name','document_name','req_name','file_label'], null); // legacy text

  $custTbl = $prefix.'customer';
  $joinCust = tableExists($db,$custTbl) ? "LEFT JOIN `".bqSQL($custTbl)."` c ON c.`id_customer` = u.`".bqSQL($c_cid)."`" : "";
  $selCust  = tableExists($db,$custTbl) ? ", c.firstname, c.lastname, c.email" : ", NULL AS firstname, NULL AS lastname, NULL AS email";

  // Optional LEFT JOIN to requirements (if both sides exist)
  $joinReq = "";
  $selReq  = ", NULL AS __req_title, NULL AS __req_gid";
  if ($reqMeta && $c_reqid) {
    $rT = bqSQL($reqMeta['table']);
    $rID= bqSQL($reqMeta['id_requirement']);
    $rTT= bqSQL($reqMeta['title']);
    $rG = $reqMeta['id_group'] ? bqSQL($reqMeta['id_group']) : null;
    $joinReq = "LEFT JOIN `{$rT}` r ON r.`{$rID}` = u.`".bqSQL($c_reqid)."`";
    $selReq  = ", r.`{$rTT}` AS __req_title".($rG ? ", r.`{$rG}` AS __req_gid" : ", NULL AS __req_gid");
  }

  $orderParts = [];
  if ($c_ts) $orderParts[] = 'u.`'.bqSQL($c_ts).'` DESC';
  if ($c_id) $orderParts[] = 'u.`'.bqSQL($c_id).'` DESC';
  $orderBy = $orderParts ? implode(', ', $orderParts) : '1 DESC';

  $sql = "
    SELECT u.*".
    ($c_id ? ", u.`".bqSQL($c_id)."` AS __id" : ", NULL AS __id").",
           u.`".bqSQL($c_cid)."` AS __cid".
    ($c_gid ? ", u.`".bqSQL($c_gid)."` AS __gid" : ", 0 AS __gid").
    ($c_reqid ? ", u.`".bqSQL($c_reqid)."` AS __rid" : ", NULL AS __rid").
    $selCust.
    $selReq."
      FROM `".bqSQL($tbl)."` u
      {$joinCust}
      {$joinReq}
     ORDER BY u.`".bqSQL($c_cid)."` ASC, {$orderBy}
     LIMIT 300
  ";
  $rows = $db->executeS($sql) ?: [];
  $totalRows = count($rows);

  foreach ($rows as $r) {
    $id     = (int)($r['__id'] ?? 0);
    $cid    = (int)$r['__cid'];
    $gidU   = (int)($r['__gid'] ?? 0);
    $rid    = isset($r['__rid']) ? (int)$r['__rid'] : 0;
    $fname  = (string)($r['firstname'] ?? '');
    $lname  = (string)($r['lastname'] ?? '');
    $email  = (string)($r['email'] ?? '');
    $file   = (string)($r[$c_name] ?? '');
    $origNm = (string)($c_orig ? ($r[$c_orig] ?? '') : '');
    $status = (string)($r[$c_st] ?? '');
    $ts     = $c_ts ? (string)($r[$c_ts] ?? '') : '';
    // Requirement text: prefer joined requirement title, then legacy column
    $reqNm  = (string)($r['__req_title'] ?? '');
    if ($reqNm === '' && $c_req) $reqNm = (string)($r[$c_req] ?? '');

    // Group: prefer requirement's id_group (if provided), else uploads.id_group
    $gidFromReq = isset($r['__req_gid']) ? (int)$r['__req_gid'] : 0;
    $gid = $gidFromReq ?: $gidU;
    $groupLabel = ($gid && isset($groupNameById[$gid])) ? $groupNameById[$gid] : '';

    if (!isset($groups[$cid])) {
      $title = '#'.$cid.
               ($fname||$lname ? ' · '.trim("$fname $lname") : '').
               ($email ? ' · '.$email : '');
      $groups[$cid] = [
        'title'    => $title,
        'key'      => strtolower($title),
        'counts'   => ['total'=>0,'approved'=>0,'rejected'=>0,'pending'=>0],
        'rows'     => [],
      ];
    }

    // Type by extension (prefer original name -> clearer ext)
    $nameForExt = $origNm ?: $file;
    $ext = extOf($nameForExt);
    $kind = isPdfExt($ext) ? 'pdf' : (isImageExt($ext) ? 'image' : 'other');

    // Secure URL to file
    $secureUrl  = $serveUrl.'?id_upload='.$id.'&inline=1';
    $previewUrl = ($kind === 'pdf') ? ($pdfViewer.'?u='.rawurlencode($secureUrl)) : $secureUrl;

    // Friendly display
    $fileBase = basename($origNm ?: ($file ?: ''));

    $groups[$cid]['rows'][] = [
      'id'        => $id,
      'gid'       => $gid,
      'rid'       => $rid,
      'file'      => $file,
      'fileBase'  => $fileBase,
      'orig'      => $origNm,
      'status'    => $status,
      'ts'        => $ts,
      'req'       => $reqNm,
      'groupName' => $groupLabel,
      'key'       => strtolower("$id $fileBase $groupLabel $reqNm $status $ts"),
      'kind'      => $kind,           // pdf | image | other
      'preview'   => $previewUrl,     // viewer for pdf, direct for image
      'directUrl' => $secureUrl,
    ];

    $groups[$cid]['counts']['total']++;
    $lc = strtolower($status);
    if ($lc==='approved') $groups[$cid]['counts']['approved']++;
    elseif ($lc==='rejected') $groups[$cid]['counts']['rejected']++;
    else $groups[$cid]['counts']['pending']++;
  }
}

uksort($groups, fn($a,$b)=>strcasecmp($groups[$a]['title'],$groups[$b]['title']));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Uploads</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf" content="<?= h($CSRF) ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#f6f8fb; }
    .page-wrap { max-width: 1200px; }
    .toolbar { gap:.5rem; flex-wrap:wrap; }
    .badge-space { min-width: 80px; }
    .btn-xs { --bs-btn-padding-y:.2rem; --bs-btn-padding-x:.5rem; --bs-btn-font-size:.78rem; }
    .accordion-button { gap:.75rem; }
    .counts .badge { margin-right:.25rem; }
    .table td { vertical-align: middle; }

    /* embedded preview row */
    tr.preview-row td { background:#fff; padding-top:0; }
    .preview-wrap { border-top:1px solid #e5e7eb; }
    .preview-toolbar{ display:flex; gap:.5rem; align-items:center; padding:.5rem .75rem; background:#fafafa; border-bottom:1px solid #eee;}
    .preview-pane { width:100%; height:80vh; border:0; display:block; }
    .image-pane   { text-align:center; padding:8px; }
    .image-pane img { max-width:100%; height:auto; display:inline-block; border:1px solid #eee; border-radius:8px; }
    .fallback-pane{ padding:16px; color:#6b7280; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; }

    /* nicer file cell */
    .filecell .fname { max-width: 580px; display:inline-block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .filecell .meta  { font-size: .84rem; color:#6b7280; }
    @media (max-width: 992px){
      .filecell .fname { max-width: 320px; }
    }
  </style>
</head>
<body>
<?php if (is_file(__DIR__.'/nav.php')) include __DIR__.'/nav.php'; ?>

<div class="container page-wrap py-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="m-0">Uploads <small class="text-muted">(latest <?= (int)$totalRows ?> records)</small></h3>
    <div class="toolbar d-flex align-items-center">
      <div class="input-group">
        <span class="input-group-text">Search</span>
        <input id="searchBox" type="text" class="form-control" placeholder="customer / file / group / requirement / status …">
      </div>
      <div class="form-check ms-2">
        <input class="form-check-input" type="checkbox" value="1" id="onlyPending">
        <label class="form-check-label" for="onlyPending">Pending only</label>
      </div>
      <button class="btn btn-outline-secondary btn-sm ms-2" id="expandAll">Expand all</button>
      <button class="btn btn-outline-secondary btn-sm" id="collapseAll">Collapse all</button>
    </div>
  </div>

  <div class="accordion" id="acc">
    <?php if (!$groups): ?>
      <div class="alert alert-info">No uploads found.</div>
    <?php else: foreach ($groups as $cid=>$g):
      $accId='cust'.$cid; $counts=$g['counts']; ?>
    <div class="accordion-item group-card" data-key="<?= h($g['key']) ?>">
      <h2 class="accordion-header" id="h<?= $accId ?>">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#b<?= $accId ?>" aria-expanded="false" aria-controls="b<?= $accId ?>">
          <span class="fw-semibold"><?= h($g['title']) ?></span>
          <span class="counts ms-auto">
            <span class="badge text-bg-secondary" title="Total"><?= (int)$counts['total'] ?></span>
            <span class="badge text-bg-success"   title="Approved"><?= (int)$counts['approved'] ?></span>
            <span class="badge text-bg-danger"    title="Rejected"><?= (int)$counts['rejected'] ?></span>
            <span class="badge text-bg-warning text-dark" title="Pending"><?= (int)$counts['pending'] ?></span>
          </span>
        </button>
      </h2>
      <div id="b<?= $accId ?>" class="accordion-collapse collapse" aria-labelledby="h<?= $accId ?>" data-bs-parent="#acc">
        <div class="accordion-body p-0">
          <div class="table-responsive">
            <table class="table table-hover mb-0">
              <thead class="table-light">
                <tr>
                  <th style="width:80px">File ID</th>
                  <th>File / Group / Requirement</th>
                  <th style="width:110px">Status</th>
                  <th style="width:170px">Uploaded</th>
                  <th style="width:220px">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($g['rows'] as $r):
                  $badge=stBadge($r['status']); ?>
                <!-- main row -->
                <tr class="file-row"
                    data-key="<?= h($r['key']) ?>"
                    data-status="<?= h(strtolower($r['status'])) ?>"
                    data-id="<?= (int)$r['id'] ?>"
                    data-kind="<?= h($r['kind']) ?>"
                    data-preview-url="<?= h($r['preview']) ?>"
                    data-direct-url="<?= h($r['directUrl']) ?>"
                    data-name="<?= h($r['fileBase']) ?>">
                  <td class="text-muted">#<?= (int)$r['id'] ?></td>

                  <!-- FILE CELL with filename-only + group + requirement -->
                  <td class="filecell">
                    <div class="fname fw-semibold" title="<?= h($r['fileBase'] ?: '—') ?>">
                      <?= h($r['fileBase'] ?: '—') ?>
                    </div>
                    <div class="meta">
                      <?= 'Group: ' . h($r['groupName'] ?: '—') ?>
                      &nbsp;•&nbsp;
                      <?= 'Requirement: ' . h($r['req'] ?: '—') ?>
                    </div>
                  </td>

                  <td><span class="badge text-bg-<?= $badge ?> badge-space"><?= h($r['status'] ?: 'pending') ?></span></td>
                  <td class="text-nowrap"><?= h($r['ts'] ?: '—') ?></td>
                  <td class="text-nowrap">
                    <button class="btn btn-primary btn-xs act-preview">Preview</button>
                    <button class="btn btn-success btn-xs act-approve" data-id="<?= (int)$r['id'] ?>">Approve</button>
                    <button class="btn btn-danger btn-xs act-reject"  data-id="<?= (int)$r['id'] ?>">Reject</button>
                  </td>
                </tr>

                <!-- hidden preview row -->
                <tr class="preview-row d-none" data-for-id="<?= (int)$r['id'] ?>">
                  <td colspan="5">
                    <div class="preview-wrap">
                      <div class="preview-toolbar">
                        <strong>Preview #<?= (int)$r['id'] ?></strong>
                        <span class="ms-auto"></span>
                        <button class="btn btn-sm btn-outline-secondary act-collapse">Close</button>
                      </div>

                      <!-- PDF pane -->
                      <iframe class="preview-pane d-none" id="pdfFrame-<?= (int)$r['id'] ?>" src="about:blank" loading="lazy"></iframe>

                      <!-- Image pane -->
                      <div class="image-pane d-none" id="imgPane-<?= (int)$r['id'] ?>">
                        <img id="imgPreview-<?= (int)$r['id'] ?>" src="" alt="">
                      </div>

                      <!-- Fallback pane -->
                      <div class="fallback-pane d-none" id="fallback-<?= (int)$r['id'] ?>">
                        Unsupported preview type.
                      </div>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- Rejection modal -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Reject file</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Reason</label>
          <select id="reasonSelect" class="form-select"></select>
        </div>
        <div class="mb-0">
          <label class="form-label">Extra notes (optional)</label>
          <textarea id="reasonExtra" class="form-control" rows="3" placeholder="Add a short explanation…"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-danger" id="rejectSave">Reject</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const q  = (s,c=document)=>c.querySelector(s);
const qa = (s,c=document)=>Array.from(c.querySelectorAll(s));

const CSRF = (q('meta[name="csrf"]')?.content)||'';
const ENDPOINTS = {
  approve: '<?= h($approveUrl) ?>',
  reject:  '<?= h($rejectUrl) ?>',
};

const PRESET_REASONS = [
  'Illegible / blurry',
  'Wrong document type',
  'Expired document',
  'Document not fully visible / cropped',
  'Selfie does not match ID',
  'Red thumbprint missing / incorrect',
  'Proof of address older than 3 months',
  'Other'
];
const LS_LAST_REASON_KEY = 'lro_last_reject_reason_v1';

/* -------- Search / Filter ---------- */
function applyFilter(){
  const term = (q('#searchBox').value||'').toLowerCase().trim();
  const onlyPending = q('#onlyPending').checked;

  qa('.group-card').forEach(card=>{
    const cardKey = (card.getAttribute('data-key')||'');
    let anyVisible = false;

    qa('.file-row', card).forEach(row=>{
      const key = (row.getAttribute('data-key')||'');
      const st  = (row.getAttribute('data-status')||'');
      const passRow = (!term || key.includes(term) || cardKey.includes(term))
                      && (!onlyPending || st==='pending');
      row.style.display = passRow ? '' : 'none';
      const id = row.getAttribute('data-id');
      const pRow = card.querySelector(`.preview-row[data-for-id="${id}"]`);
      if (pRow) pRow.style.display = passRow ? '' : 'none';
      if (passRow) anyVisible = true;
    });

    card.style.display = anyVisible ? '' : 'none';
  });
}
document.addEventListener('input', e=>{
  if (e.target.id==='searchBox' || e.target.id==='onlyPending') applyFilter();
});

q('#expandAll')?.addEventListener('click', ()=>{
  qa('.accordion-collapse').forEach(el=>{ if (!el.classList.contains('show')) new bootstrap.Collapse(el,{toggle:true}).show(); });
});
q('#collapseAll')?.addEventListener('click', ()=>{
  qa('.accordion-collapse').forEach(el=>{ if (el.classList.contains('show')) new bootstrap.Collapse(el,{toggle:true}).hide(); });
});

/* -------- Preview logic (PDF vs Image) ---------- */
function closePreviewForRow(row){
  const id = row.getAttribute('data-id');
  const pRow = row.parentElement.querySelector(`.preview-row[data-for-id="${id}"]`);
  if (!pRow) return;

  const pdfFrame = q('#pdfFrame-'+id);
  const imgEl    = q('#imgPreview-'+id);
  const pdfPane  = q('#pdfFrame-'+id);
  const imgPane  = q('#imgPane-'+id);
  const fallback = q('#fallback-'+id);
  if (pdfFrame) pdfFrame.src='about:blank';
  if (imgEl)    imgEl.src='';
  if (pdfPane)  pdfPane.classList.add('d-none');
  if (imgPane)  imgPane.classList.add('d-none');
  if (fallback) fallback.classList.add('d-none');

  pRow.classList.add('d-none');
}

function openPreviewForRow(row){
  const id    = row.getAttribute('data-id');
  const kind  = (row.getAttribute('data-kind')||'other');
  const url   = row.getAttribute('data-preview-url'); // pdf viewer OR direct image
  const direct= row.getAttribute('data-direct-url');  // direct file url if needed
  const pRow  = row.parentElement.querySelector(`.preview-row[data-for-id="${id}"]`);
  if (!pRow) return;

  // Close others in same tbody
  qa('.preview-row', row.parentElement).forEach(r=>{
    if (r!==pRow && !r.classList.contains('d-none')) {
      r.classList.add('d-none');
      const rid = r.getAttribute('data-for-id');
      const rf = q('#pdfFrame-'+rid);
      const ri = q('#imgPreview-'+rid);
      if (rf) rf.src='about:blank';
      if (ri) ri.src='';
      q('#imgPane-'+rid)?.classList.add('d-none');
      q('#fallback-'+rid)?.classList.add('d-none');
      q('#pdfFrame-'+rid)?.classList.add('d-none');
    }
  });

  // panes
  const pdfFrame = q('#pdfFrame-'+id);
  const imgEl    = q('#imgPreview-'+id);
  const imgPane  = q('#imgPane-'+id);
  const fallback = q('#fallback-'+id);

  // reset
  if (pdfFrame) { pdfFrame.src='about:blank'; pdfFrame.classList.add('d-none'); }
  if (imgEl)    { imgEl.src=''; }
  if (imgPane)  imgPane.classList.add('d-none');
  if (fallback) fallback.classList.add('d-none');

  if (kind === 'pdf') {
    pdfFrame.src = url;                 // PDF.js viewer url
    pdfFrame.classList.remove('d-none');
  } else if (kind === 'image') {
    imgEl.src = direct;                 // serve_file.php (&inline=1)
    imgPane.classList.remove('d-none');
  } else {
    fallback.classList.remove('d-none');
  }

  pRow.classList.remove('d-none');
}

document.addEventListener('click', e=>{
  const previewBtn  = e.target.closest('.act-preview');
  const collapseBtn = e.target.closest('.act-collapse');

  if (previewBtn){
    const row = previewBtn.closest('.file-row');
    const id  = row?.getAttribute('data-id');
    const pRow = row?.parentElement.querySelector(`.preview-row[data-for-id="${id}"]`);
    if (!row || !pRow) return;
    if (pRow.classList.contains('d-none')) openPreviewForRow(row);
    else closePreviewForRow(row);
  }

  if (collapseBtn){
    const pRow = collapseBtn.closest('.preview-row');
    if (!pRow) return;
    const id = pRow.getAttribute('data-for-id');
    const row = pRow.parentElement.querySelector(`.file-row[data-id="${id}"]`);
    if (row) closePreviewForRow(row);
  }
});

/* -------- actions: approve / reject ---------- */
async function postJSON(url, data){
  const fd = new FormData(); for (const [k,v] of Object.entries(data)) fd.append(k, v ?? '');
  const res = await fetch(url, { method:'POST', body:fd, credentials:'include' });
  let json=null; try{ json=await res.json(); }catch(_){}
  if (!res.ok || !json || json.ok===false){
    const msg = (json && (json.error||json.message)) || ('HTTP '+res.status);
    throw new Error(msg);
  }
  return json;
}
function setBadge(row, status){
  const b=row.querySelector('.badge');
  const s=String(status||'').toLowerCase();
  b.textContent = status;
  b.classList.remove('text-bg-success','text-bg-danger','text-bg-secondary','text-bg-info');
  if (s==='approved') b.classList.add('text-bg-success');
  else if (s==='rejected') b.classList.add('text-bg-danger');
  else if (s==='pending' || s==='') b.classList.add('text-bg-secondary');
  else b.classList.add('text-bg-info');
  row.setAttribute('data-status', s);
}
function bumpCounts(card, from, to){
  const badges = qa('.counts .badge', card);
  const map = { total:0, approved:1, rejected:2, pending:3 };
  if (from && map[from]!=null) badges[map[from]].textContent = Math.max(0, parseInt(badges[map[from]].textContent||'0',10)-1);
  if (to   && map[to]!=null)   badges[map[to]].textContent   = parseInt(badges[map[to]].textContent||'0',10)+1;
}

/* rejection modal */
let rejectCtx = { row:null, card:null, id:null };
const rejectModalEl = q('#rejectModal');
const rejectModal   = new bootstrap.Modal(rejectModalEl);

function fillReasons(){
  const sel = q('#reasonSelect');
  sel.innerHTML = PRESET_REASONS.map(r => `<option value="${r.replace(/"/g,'&quot;')}">${r}</option>`).join('');
  const last = localStorage.getItem(LS_LAST_REASON_KEY);
  if (last && PRESET_REASONS.includes(last)) sel.value = last;
}
document.addEventListener('DOMContentLoaded', fillReasons);

q('#rejectSave')?.addEventListener('click', async ()=>{
  if (!rejectCtx.id || !rejectCtx.row || !rejectCtx.card) return;

  const sel   = q('#reasonSelect').value || '';
  const extra = (q('#reasonExtra').value || '').trim();
  const reason = (sel==='Other') ? (extra || 'Other') : (extra ? `${sel} — ${extra}` : sel);

  try{
    q('#rejectSave').disabled = true;
    await postJSON(ENDPOINTS.reject, { csrf_token: CSRF, file_id: rejectCtx.id, reason_text: reason });
    const prev = (rejectCtx.row.getAttribute('data-status')||'');
    setBadge(rejectCtx.row,'rejected');
    bumpCounts(rejectCtx.card, prev, 'rejected');
    localStorage.setItem(LS_LAST_REASON_KEY, sel);
    rejectModal.hide();
    q('#reasonExtra').value = '';
    applyFilter();
  }catch(err){
    alert('Action failed: ' + (err.message||err));
  }finally{
    q('#rejectSave').disabled = false;
  }
});

document.addEventListener('click', async (e)=>{
  const approveBtn = e.target.closest('.act-approve');
  const rejectBtn  = e.target.closest('.act-reject');
  if (!approveBtn && !rejectBtn) return;

  const row  = (approveBtn||rejectBtn).closest('tr.file-row');
  const card = (approveBtn||rejectBtn).closest('.group-card');
  const id   = (approveBtn||rejectBtn).getAttribute('data-id');
  if (!row || !card || !id) return;

  if (approveBtn){
    try{
      approveBtn.disabled = true;
      await postJSON(ENDPOINTS.approve, { csrf_token: CSRF, file_id: id });
      const prev = (row.getAttribute('data-status')||'');
      setBadge(row,'approved');
      bumpCounts(card, prev, 'approved');
      applyFilter();
    }catch(err){
      alert('Action failed: ' + (err.message||err));
    }finally{
      approveBtn.disabled = false;
    }
    return;
  }

  rejectCtx = { row, card, id };
  fillReasons();
  q('#reasonExtra').value = '';
  rejectModal.show();
});

// initial filter pass
applyFilter();
</script>
</body>
</html>
