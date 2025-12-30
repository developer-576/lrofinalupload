<?php
/**************************************************
 * modules/lrofileupload/admin/credential_card_viewer.php
 **************************************************/
declare(strict_types=1);

ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

/* ---------- Single bootstrap (PS + session + helpers) ---------- */
require_once __DIR__.'/_bootstrap.php';

/* ---------- Session & Auth ---------- */
if (session_status() === PHP_SESSION_NONE) session_start();

if (function_exists('lro_require_admin')) {
  lro_require_admin(false); // not master-only
} elseif (function_exists('require_admin_login')) {
  require_admin_login(false);
} else {
  if (empty($_SESSION['admin_id'])) { http_response_code(403); exit('Forbidden (admins only)'); }
}
if (function_exists('require_cap')) require_cap('can_view_credential_card');

if (function_exists('admin_log')) {
  admin_log('view:credential_card_viewer', [
    'admin_id' => $_SESSION['admin_id'] ?? null,
    'ip'       => $_SERVER['REMOTE_ADDR'] ?? null,
    'ua'       => $_SERVER['HTTP_USER_AGENT'] ?? null,
  ]);
}

/* ---------- Setup ---------- */
$db     = Db::getInstance();
$prefix = _DB_PREFIX_;
$base   = rtrim(Tools::getShopDomainSsl(true), '/') . rtrim(__PS_BASE_URI__ ?? '/', '/');

$pdfjsPathOnFs  = _PS_MODULE_DIR_ . 'lrofileupload/admin/pdfjs/web/viewer.html';
$pdfjsAvailable = file_exists($pdfjsPathOnFs);
$pdfViewer      = $base . '/modules/lrofileupload/admin/pdfjs/web/viewer.html';

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf_token'];

/* Column config */
$REQUIRED = [
  ['label' => 'Proof of address', 'slugs' => ['proof_of_address','poa','address','proof_address']],
  ['label' => 'ID document',      'slugs' => ['id','id_document','identity','identity_document','id_doc']],
  ['label' => 'Selfie',           'slugs' => ['selfie']],
  ['label' => 'Red thumbprint',   'slugs' => ['seal','red_seal','res_seal','red_thumbprint','fingerprint']],
];

/* Helpers */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function norm_slug(string $name): string {
  $s = mb_strtolower(trim($name), 'UTF-8');
  $s = preg_replace('/[^\p{L}\p{N}]+/u', '_', $s);
  $s = preg_replace('/_+/', '_', $s);
  return trim($s, '_');
}
function findDoc(array $docs, array $accepted) {
  foreach ($accepted as $slug) if (isset($docs[$slug])) return $docs[$slug];
  return null;
}
function tableExists(Db $db, string $table): bool {
  $sql = "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '".pSQL($table)."'";
  return (int)$db->getValue($sql) > 0;
}
function tableCols(Db $db, string $table): array {
  if (!tableExists($db, $table)) return [];
  $rows = $db->executeS("SELECT COLUMN_NAME AS c FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '".pSQL($table)."'") ?: [];
  $out = [];
  foreach ($rows as $r) $out[strtolower((string)$r['c'])] = true;
  return $out;
}
function firstExisting(array $cols, array $candidates): ?string {
  foreach ($candidates as $c) { $lc = strtolower($c); if (isset($cols[$lc])) return $c; }
  return null;
}

/* ---------- Approved uploads (latest per requirement per customer) ---------- */
$uploads    = [];
$uploadsTbl = $prefix . 'lrofileupload_uploads';

if (tableExists($db, $uploadsTbl)) {
  $uCols = tableCols($db, $uploadsTbl);

  // Detect uploads table columns
  $uPk   = firstExisting($uCols, ['file_id','id_upload','upload_id','id']) ?: 'id_upload';
  $uFn   = firstExisting($uCols, ['file_name','filename','original_name','name']) ?: 'file_name';
  $uSt   = firstExisting($uCols, ['status','state']) ?: 'status';
  $uTs   = firstExisting($uCols, ['uploaded_at','date_uploaded','date_add','created_at','created','ts']) ?: 'uploaded_at';

  $uCust = firstExisting($uCols, ['id_customer','customer_id','customer']) ?: 'id_customer';
  $uGrp  = firstExisting($uCols, ['id_group','group_id','gid','groupid']); // optional
  $uReq  = firstExisting($uCols, ['id_requirement','requirement_id','req_id','rid']); // optional
  $uReqName = firstExisting($uCols, ['requirement_name','req_name','document_name','doc_name']); // optional

  // NEW (optional) order reference columns
  $uOrderRef = firstExisting($uCols, ['order_reference','order_ref','reference','txn_ref','transaction_ref']); // text
  $uOrderId  = firstExisting($uCols, ['id_order','order_id']); // numeric

  // Optional requirement tables
  $grTbl  = $prefix.'lrofileupload_group_requirements';
  $drTbl  = $prefix.'lrofileupload_requirements';
  $dr2Tbl = $prefix.'lrofileupload_document_requirements';

  $haveGR  = tableExists($db, $grTbl);
  $haveDR  = tableExists($db, $drTbl);
  $haveDR2 = tableExists($db, $dr2Tbl);

  // Detect *existing* label columns on each optional table
  $grNameCol  = $haveGR  ? firstExisting(tableCols($db,$grTbl),  ['requirement_name','name','title','label','file_name','document_name']) : null;
  $drNameCol  = $haveDR  ? firstExisting(tableCols($db,$drTbl),  ['file_name','name','title','label','requirement_name','document_name']) : null;
  $dr2NameCol = $haveDR2 ? firstExisting(tableCols($db,$dr2Tbl), ['document_name','name','title','label','file_name','requirement_name']) : null;

  // Build COALESCE(requirement_name) expression – *only* include columns that exist
  $partsReq = [];
  if ($uReqName)         $partsReq[] = 'u.`'.bqSQL($uReqName).'`';
  if ($haveGR  && $uReq && $grNameCol)  $partsReq[] = 'gr.`'.bqSQL($grNameCol).'`';
  if ($haveDR  && $uReq && $drNameCol)  $partsReq[] = 'dr.`'.bqSQL($drNameCol).'`';
  if ($haveDR2 && $uReq && $dr2NameCol) $partsReq[] = 'dr2.`'.bqSQL($dr2NameCol).'`';
  // always fall back to the file name
  $partsReq[] = 'u.`'.bqSQL($uFn).'`';
  $reqNameExpr = 'COALESCE('.implode(', ', $partsReq).')';

  // Optional join to orders if uploads table stores id_order only
  $joins = '';
  $orderRefExpr = 'NULL';
  if ($uOrderRef) {
    $orderRefExpr = 'u.`'.bqSQL($uOrderRef).'`';
  }
  if ($uOrderId) {
    $joins .= ' LEFT JOIN `'.bqSQL($prefix.'orders').'` o ON o.`id_order` = u.`'.bqSQL($uOrderId).'`';
    $orderRefExpr = 'COALESCE('.$orderRefExpr.', o.`reference`)';
  }

  // Safe group/customer/req selections
  $selCust = 'u.`'.bqSQL($uCust).'` AS id_customer';
  $selGrp  = $uGrp ? ('COALESCE(u.`'.bqSQL($uGrp).'`,0) AS id_group') : ('0 AS id_group');
  $selReq  = $uReq ? ('u.`'.bqSQL($uReq).'` AS id_requirement') : ('0 AS id_requirement');

  // Build rel path with optional ref segment
  $relGroupExpr = $uGrp ? 'COALESCE(u.`'.bqSQL($uGrp).'`,0)' : '0';
  $refSegExpr   = "CASE WHEN ".$orderRefExpr." IS NULL OR ".$orderRefExpr."='' THEN '' ELSE CONCAT('ref_', ".$orderRefExpr.", '/') END";
  $relPathExpr  = "CONCAT('customer_', u.`".bqSQL($uCust)."`, '/group_', ".$relGroupExpr.", '/', ".$refSegExpr.", u.`".bqSQL($uFn)."` )";

  // Optional joins to requirement tables (only if both table and id column present)
  if ($haveGR && $uReq)  $joins .= ' LEFT JOIN `'.bqSQL($grTbl).'`  gr ON gr.`id_requirement` = u.`'.bqSQL($uReq).'`';
  if ($haveDR && $uReq)  $joins .= ' LEFT JOIN `'.bqSQL($drTbl).'`  dr ON dr.`id_requirement` = u.`'.bqSQL($uReq).'`';
  if ($haveDR2 && $uReq) $joins .= ' LEFT JOIN `'.bqSQL($dr2Tbl).'` dr2 ON dr2.`id_requirement`= u.`'.bqSQL($uReq).'`';

  $sql = "
    SELECT
      u.`".bqSQL($uPk)."` AS id_upload,
      {$selCust},
      {$selGrp},
      {$selReq},
      u.`".bqSQL($uFn)."` AS file_name,
      u.`".bqSQL($uSt)."` AS status,
      u.`".bqSQL($uTs)."` AS uploaded_at,
      c.firstname, c.lastname, c.email,
      {$reqNameExpr} AS requirement_name,
      {$relPathExpr} AS rel_path,
      {$orderRefExpr} AS order_reference
    FROM `".bqSQL($uploadsTbl)."` u
    LEFT JOIN `".bqSQL($prefix.'customer')."` c
           ON c.id_customer = u.`".bqSQL($uCust)."`
    {$joins}
    ".($uSt ? "WHERE LOWER(u.`".bqSQL($uSt)."`)= 'approved'" : "WHERE 1=1")."
    ORDER BY u.`".bqSQL($uCust)."`, u.`".bqSQL($uTs)."` DESC, u.`".bqSQL($uPk)."` DESC
  ";

  $uploads = $db->executeS($sql) ?: [];
}

/* Latest order reference per customer (for search chip) */
$refs = [];
$ordersTbl = $prefix . 'orders';
if (tableExists($db, $ordersTbl)) {
  $rows = $db->executeS("
    SELECT o.id_customer, o.reference
      FROM `{$ordersTbl}` o
      JOIN (
            SELECT id_customer, MAX(date_add) AS last_date
              FROM `{$ordersTbl}`
             GROUP BY id_customer
           ) t ON t.id_customer = o.id_customer AND t.last_date = o.date_add
  ") ?: [];
  foreach ($rows as $r) $refs[(int)$r['id_customer']] = (string)$r['reference'];
}

/* Group docs by customer; keep first (newest) per slug */
$byCustomer = [];
foreach ($uploads as $row) {
  $cid   = (int)($row['id_customer'] ?? 0);
  $fname = trim((string)($row['firstname'] ?? ''));
  $lname = trim((string)($row['lastname']  ?? ''));

  if (!isset($byCustomer[$cid])) {
    $byCustomer[$cid] = [
      'cid'  => $cid,
      'name' => trim(($fname ?: 'Anonymous').' '.($lname ?: '')),
      'email'=> (string)($row['email'] ?? ''),
      'ref'  => $refs[$cid] ?? '',
      'docs' => [],
    ];
  }
  $label = (string)($row['requirement_name'] ?? '');
  $slug  = $label !== '' ? norm_slug($label) : '';
  if ($slug && !isset($byCustomer[$cid]['docs'][$slug])) {
    $byCustomer[$cid]['docs'][$slug] = $row;
  }
}
uasort($byCustomer, fn($a,$b)=>strcasecmp($a['name'],$b['name']));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Credential Card Viewer</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf" content="<?= h($CSRF) ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#f7f9fc; }
    .customer-card { border:1px solid #e5e7eb; border-radius:.5rem; background:#fff; overflow:hidden; }
    .header { background:#0d6efd; color:#fff; padding:.6rem .9rem; font-weight:600; display:flex; gap:.5rem; align-items:center; justify-content:space-between; flex-wrap:wrap; }
    .ref-chip { background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.35); padding:.15rem .5rem; border-radius:.4rem; font-weight:500; }
    .pending-badge { display:inline-block; background:#dc3545; color:#fff; padding:2px 7px; border-radius:6px; font-size:.78rem; }
    .muted { color:#6b7280; font-size:.85rem; }
    .search-wrap { max-width:520px; gap:.5rem; }
  </style>
</head>
<body>
<?php if (file_exists(__DIR__.'/nav.php')) include __DIR__.'/nav.php'; ?>

<div class="container py-4">
  <h3 class="mb-3">Credential Card Viewer</h3>

  <div class="d-flex search-wrap mb-3">
    <input id="searchBox" type="text" class="form-control" placeholder="Search customer name or reference…">
    <button class="btn btn-outline-secondary" id="clearBtn">Clear</button>
  </div>

  <!-- Directory matches -->
  <div id="dirWrap" class="mb-3">
    <div class="card">
      <div class="card-header">Directory matches (even if no approved docs yet)</div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead class="table-light">
              <tr><th style="width:36%">Customer</th><th>Email</th><th style="width:18%">Latest Ref</th></tr>
            </thead>
            <tbody id="dirBody"><tr><td colspan="3" class="text-muted">Type to search…</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <?php if (!$byCustomer): ?>
    <div id="noCardsNote" class="alert alert-info">No approved documents yet. Use the search above to find a customer in the directory.</div>
  <?php else: ?>
    <div id="customers">
      <?php foreach ($byCustomer as $cust): ?>
        <?php $ref = $cust['ref']; $searchKey = mb_strtolower(trim(($cust['name'] ?? '') . ' ' . ($ref ?? ''))); ?>
        <div class="customer-card mb-4" data-key="<?= h($searchKey) ?>">
          <div class="header">
            <div>
              <?= h($cust['name'] ?: 'Anonymous Anonymous') ?>
              <?php if ($ref): ?><span class="ref-chip ms-2">Ref: <?= h($ref) ?></span><?php endif; ?>
            </div>

            <div class="d-flex align-items-center gap-2">
              <input type="hidden" name="customer_id" value="<?= (int)$cust['cid'] ?>">
              <form class="d-flex align-items-center gap-2 m-0" method="post" action="download_selected.php" onsubmit="return prepareBulkDownload(this)">
                <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
                <input type="hidden" name="customer_id" value="<?= (int)$cust['cid'] ?>">
                <input type="hidden" name="files_json" value="">
                <button class="btn btn-sm btn-outline-light" type="submit">Download selected</button>
              </form>
              <button class="btn btn-sm btn-warning" type="button" onclick="saveSelectedToFolder(this)" title="Pick a folder and save the selected originals">Save selected…</button>
              <button class="btn btn-sm btn-outline-light" type="button" onclick="saveAllToFolder(this)" title="Pick a folder and save all files shown on this card">Save all…</button>
              <button class="btn btn-sm btn-outline-light" type="button" onclick="saveUpdatedToFolder(this)" title="Save only files updated since your last save (per this browser)">Save updated…</button>
              <span class="muted">(tick files below)</span>
            </div>
          </div>

          <div class="p-3">
            <div class="table-responsive">
              <table class="table table-bordered align-middle text-center mb-0">
                <thead class="table-light"><tr><?php foreach ($REQUIRED as $it): ?><th><?= h($it['label']) ?></th><?php endforeach; ?></tr></thead>
                <tbody><tr>
                  <?php foreach ($REQUIRED as $it):
                    $doc = findDoc($cust['docs'], $it['slugs']); ?>
                    <td class="text-center">
                      <?php if ($doc && !empty($doc['file_name']) && !empty($doc['rel_path'])):
                        $rel   = (string)$doc['rel_path'];
                        $serve = $base . '/modules/lrofileupload/admin/serve_file_safe.php?file=' . rawurlencode($rel);
                        $fn    = (string)$doc['file_name'];
                        $ext   = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
                        $isImg = in_array($ext,['jpg','jpeg','png','gif','webp'],true);
                        $isPdf = ($ext==='pdf');
                        $previewHref = $isImg ? $serve : ($isPdf && $pdfjsAvailable ? ($pdfViewer.'?file='.rawurlencode($serve)) : $serve);
                        $uploadedAt = (string)($doc['uploaded_at'] ?? ''); $uploadedTs = $uploadedAt ? strtotime($uploadedAt) : time();
                        $orderRef   = (string)($doc['order_reference'] ?? '');
                      ?>
                        <div class="d-grid gap-1">
                          <div class="form-check d-flex justify-content-center">
                            <input class="form-check-input select-file" type="checkbox" data-rel="<?= h($rel) ?>" data-name="<?= h($fn) ?>" data-updated="<?= (int)$uploadedTs ?>">
                          </div>
                          <a class="btn btn-sm btn-outline-info" target="_blank" rel="noopener" href="<?= h($previewHref) ?>">Preview</a>
                          <a class="btn btn-sm btn-success" href="<?= h($serve) ?>&download=1">Download</a>
                          <?php if ($orderRef): ?><div class="muted">Ref: <?= h($orderRef) ?></div><?php endif; ?>
                          <input type="file" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf" class="d-none up-input"
                                 data-id_customer="<?= (int)$cust['cid'] ?>" data-id_group="<?= (int)($doc['id_group'] ?? 0) ?>"
                                 data-id_requirement="<?= (int)($doc['id_requirement'] ?? 0) ?>" data-requirement="<?= h($it['label']) ?>"
                                 data-old_rel="<?= h($rel) ?>">
                          <button type="button" class="btn btn-sm btn-outline-primary" onclick="triggerReplace(this)">Add/Replace…</button>
                          <?php if ($uploadedAt): ?><div class="muted"><?= h($uploadedAt) ?></div><?php endif; ?>
                        </div>
                      <?php else: ?>
                        <span class="pending-badge">Pending</span>
                        <div class="mt-2">
                          <input type="file" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf" class="d-none up-input"
                                 data-id_customer="<?= (int)$cust['cid'] ?>" data-id_group="0" data-id_requirement="0" data-requirement="<?= h($it['label']) ?>">
                          <button type="button" class="btn btn-sm btn-outline-primary" onclick="triggerReplace(this)">Add file…</button>
                        </div>
                      <?php endif; ?>
                    </td>
                  <?php endforeach; ?>
                </tr></tbody>
              </table>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<script>
const q  = (s,c=document)=>c.querySelector(s);
const qa = (s,c=document)=>Array.from(c.querySelectorAll(s));

/* ---------------- Search (cards + directory) ---------------- */
let dirTimer = null;
function applyCardFilter(term){
  const cards = qa('#customers .customer-card');
  cards.forEach(card=>{
    const key = (card.getAttribute('data-key') || '').toLowerCase();
    card.style.display = (!term || key.includes(term)) ? '' : 'none';
  });
}
async function fetchDirectory(term){
  try{
    const res = await fetch('customer_lookup.php?q=' + encodeURIComponent(term), { cache:'no-store', credentials:'include' });
    const data = await res.json();
    return (data && data.rows) ? data.rows : [];
  }catch(e){ return []; }
}
function escapeHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }
function renderDirectory(rows){
  const body = q('#dirBody');
  if (!rows || rows.length===0){ body.innerHTML = `<tr><td colspan="3" class="text-muted">No directory matches.</td></tr>`; return; }
  const renderedIds = new Set(qa('#customers .customer-card input[name="customer_id"]').map(i=>parseInt(i.value,10)));
  const html = rows.filter(r=>!renderedIds.has(parseInt(r.id_customer,10))).map(r=>{
    const name = `${r.firstname ?? ''} ${r.lastname ?? ''}`.trim() || 'Anonymous';
    const email = r.email || '';
    const ref = r.reference || '';
    return `<tr><td>${escapeHtml(name)}</td><td class="text-muted">${escapeHtml(email)}</td>
            <td>${ref ? `<span class="badge text-bg-secondary">${escapeHtml(ref)}</span>` : '<span class="text-muted">—</span>'}</td></tr>`;
  }).join('');
  body.innerHTML = html || `<tr><td colspan="3" class="text-muted">No directory matches.</td></tr>`;
}
async function onSearchInput(){
  const term = (q('#searchBox').value || '').toLowerCase().trim();
  if (q('#customers')) applyCardFilter(term);
  if (dirTimer) clearTimeout(dirTimer);
  if (term.length < 2){ q('#dirBody').innerHTML = `<tr><td colspan="3" class="text-muted">Type to search…</td></tr>`; return; }
  dirTimer = setTimeout(async ()=>{ const rows = await fetchDirectory(term); renderDirectory(rows); }, 250);
}
document.addEventListener('DOMContentLoaded', ()=> {
  q('#searchBox').addEventListener('input', onSearchInput);
  q('#clearBtn').addEventListener('click', ()=>{ q('#searchBox').value=''; onSearchInput(); });
});

/* ---------------- Bulk download ---------------------- */
function prepareBulkDownload(form){
  const box = form.closest('.customer-card');
  const ticks = qa('.select-file:checked', box).map(cb => cb.getAttribute('data-rel'));
  if (ticks.length === 0){ alert('Please tick at least one file.'); return false; }
  if (ticks.length > 50){ alert('Too many files selected.'); return false; }
  form.files_json.value = JSON.stringify(ticks);
  return true;
}

/* ---------------- Save-to-folder (File System Access API) ---- */
const SAVED_INDEX_KEY = 'lro_saved_index_v1';
function getSavedIndex(){ try{ return JSON.parse(localStorage.getItem(SAVED_INDEX_KEY) || '{}') || {}; }catch(e){ return {}; } }
function setSavedIndex(map){ try{ localStorage.setItem(SAVED_INDEX_KEY, JSON.stringify(map)); }catch(e){} }
function markSaved(rel, ts){ const idx=getSavedIndex(); idx[rel]=Math.max(ts||0, idx[rel]||0); setSavedIndex(idx); }

async function saveFilesToFolder(fileObjs){
  if (!fileObjs.length){ alert('No files to save.'); return; }
  if (!window.showDirectoryPicker){ alert('Your browser does not support choosing a folder.'); return; }
  const dir = await window.showDirectoryPicker({ id:'lro-save-dir', mode:'readwrite' });
  for (const item of fileObjs){
    const url = '<?= h($base) ?>/modules/lrofileupload/admin/serve_file_safe.php?file=' + encodeURIComponent(item.rel) + '&download=1';
    const res = await fetch(url, { credentials:'include', cache:'no-store' });
    if (!res.ok) throw new Error('Failed: ' + item.name);
    const handle = await dir.getFileHandle(item.name, { create:true });
    const writable = await handle.createWritable();
    await res.body.pipeTo(writable);
    if (item.updatedEpoch) markSaved(item.rel, item.updatedEpoch);
  }
}
function collectSelected(box){ return Array.from(box.querySelectorAll('.select-file:checked')).map(cb => ({ rel: cb.dataset.rel, name: cb.dataset.name || cb.dataset.rel.split('/').pop(), updatedEpoch: parseInt(cb.dataset.updated||'0',10)})); }
function collectAll(box){ return Array.from(box.querySelectorAll('.select-file')).map(cb => ({ rel: cb.dataset.rel, name: cb.dataset.name || cb.dataset.rel.split('/').pop(), updatedEpoch: parseInt(cb.dataset.updated||'0',10)})); }
function collectUpdated(box){ const idx=getSavedIndex(); return Array.from(box.querySelectorAll('.select-file')).map(cb=>{ const rel=cb.dataset.rel; const upd=parseInt(cb.dataset.updated||'0',10); return { rel, name: cb.dataset.name || rel.split('/').pop(), updatedEpoch: upd, isUpdated:(upd||0)>(idx[rel]||0)}; }).filter(x=>x.isUpdated); }

async function saveSelectedToFolder(btn){ const box=btn.closest('.customer-card'); const files=collectSelected(box); if(!files.length){ alert('Select files first.'); return; } try{ await saveFilesToFolder(files); alert('Saved '+files.length+' file(s).'); }catch(e){ if(e.name!=='AbortError') alert('Saving failed: '+(e.message||e)); } }
async function saveAllToFolder(btn){ const box=btn.closest('.customer-card'); const files=collectAll(box); if(!files.length){ alert('No files available.'); return; } try{ await saveFilesToFolder(files); alert('Saved '+files.length+' file(s).'); }catch(e){ if(e.name!=='AbortError') alert('Saving failed: '+(e.message||e)); } }
async function saveUpdatedToFolder(btn){ const box=btn.closest('.customer-card'); let files=collectUpdated(box); if(!files.length){ alert('No updated files.'); return; } try{ await saveFilesToFolder(files); alert('Saved '+files.length+' updated file(s).'); }catch(e){ if(e.name!=='AbortError') alert('Saving failed: '+(e.message||e)); } }

/* ---------------- Add/Replace upload ------------------------- */
function triggerReplace(btn){
  const cell = btn.closest('td');
  const inp = cell.querySelector('.up-input');
  if (!inp){ alert('Upload input not found.'); return; }
  inp.onchange = () => { if (inp.files && inp.files[0]) doReplaceUpload(inp, cell, btn); };
  inp.click();
}
async function doReplaceUpload(inputEl, cell, btn){
  try{
    btn.disabled = true; btn.textContent = 'Uploading…';
    const fd = new FormData();
    fd.append('csrf_token', document.querySelector('meta[name="csrf"]').content || '');
    fd.append('id_customer', inputEl.dataset.id_customer || '');
    fd.append('id_group', inputEl.dataset.id_group || '');
    fd.append('id_requirement', inputEl.dataset.id_requirement || '');
    fd.append('requirement', inputEl.dataset.requirement || '');
    if (inputEl.dataset.old_rel) fd.append('old_rel', inputEl.dataset.old_rel);
    fd.append('upload', inputEl.files[0]);

    const res = await fetch('ajax_replace_upload.php', { method:'POST', body:fd, credentials:'include' });
    const json = await res.json();
    if (!json || !json.success) throw new Error((json && (json.error||json.message)) || 'Upload failed');

    const rel = json.rel_path, name=json.file_name, updated=json.uploaded_at, serve=json.serve_url;
    const isImg = /\.(jpe?g|png|gif|webp)$/i.test(name);
    const isPdf = /\.pdf$/i.test(name);
    const pv = isImg ? serve : (isPdf ? '<?= h($pdfViewer) ?>' + '?file=' + encodeURIComponent(serve) : serve);

    let cb = cell.querySelector('.select-file');
    if (!cb){
      const wrap = document.createElement('div'); wrap.className='form-check d-flex justify-content-center';
      cb = document.createElement('input'); cb.type='checkbox'; cb.className='form-check-input select-file'; wrap.appendChild(cb); cell.prepend(wrap);
    }
    cb.dataset.rel = rel; cb.dataset.name = name; cb.dataset.updated = Math.floor(Date.parse(updated)/1000);

    let previewBtn = cell.querySelector('a.btn-outline-info');
    let downloadBtn= cell.querySelector('a.btn.btn-success');
    if (!previewBtn){ previewBtn = document.createElement('a'); previewBtn.className='btn btn-sm btn-outline-info'; previewBtn.target='_blank'; previewBtn.rel='noopener'; previewBtn.textContent='Preview'; cell.appendChild(previewBtn); }
    if (!downloadBtn){ downloadBtn = document.createElement('a'); downloadBtn.className='btn btn-sm btn-success'; downloadBtn.textContent='Download'; cell.appendChild(downloadBtn); }
    previewBtn.href = pv; downloadBtn.href = serve + '&download=1';

    let ts = cell.querySelector('.muted'); if (!ts){ ts = document.createElement('div'); ts.className='muted'; cell.appendChild(ts); }
    ts.textContent = updated;

    const pending = cell.querySelector('.pending-badge'); if (pending) pending.remove();
    inputEl.dataset.old_rel = rel;

    alert('File saved.');
  }catch(e){ alert(e.message || 'Upload failed'); }
  finally{ btn.disabled=false; btn.textContent='Add/Replace…'; inputEl.value=''; }
}
</script>
</body>
</html>
