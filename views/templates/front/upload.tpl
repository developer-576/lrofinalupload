{* modules/lrofileupload/views/templates/front/upload.tpl *}
{extends file='page.tpl'}

{block name='page_content'}

<style>
/* ---------- Layout Shell ---------- */
.lro-upload-shell {
    background: #f8fafc;
    border-radius: 1rem;
    padding: 2rem 2rem 2.5rem;
    margin: 2rem auto 3rem;
    max-width: 1100px;
    box-shadow: 0 8px 25px rgba(0,0,0,.08);
}

/* Title */
.lro-upload-title {
    font-size: 1.7rem;
    font-weight: 700;
    letter-spacing: .015em;
}
.lro-upload-sub {
    font-size: .9rem;
    color: #6c757d;
}

/* Divider */
.lro-divider {
    margin: 1.5rem 0;
    border-top: 1px solid rgba(0,0,0,.08);
}

/* ---------- Group CARD (full width, collapsible) ---------- */
.lro-card {
    border: 0;
    border-radius: .9rem;
    overflow: hidden;
    background: white;
    box-shadow: 0 10px 28px rgba(0,0,0,.1);
    margin-bottom: 1.25rem;
}

.lro-card-header {
    padding: 1rem 1.25rem;
    background: linear-gradient(135deg, #007bff, #0056d6);
    color: #fff;
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
}

.lro-card-title {
    font-size: 1.2rem;
    font-weight: 600;
}

.lro-card-sub {
    font-size: .8rem;
    opacity: .9;
}

/* Collapse indicator (simple plus/minus, no weird glyphs) */
.lro-collapse-indicator {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    border: 1px solid rgba(255,255,255,.7);
    font-size: 14px;
    line-height: 1;
    font-weight: 700;
    background: rgba(0,0,0,.15);
}
.lro-card-header[data-open="1"] .lro-collapse-indicator {
    background: rgba(0,0,0,.25);
}

/* Body wrapper (for collapsing) */
.lro-card-body-wrap {
    padding: 0;
}

/* ---------- Table ---------- */
.lro-table {
    margin-bottom: 0;
}
.lro-table thead {
    background: #f1f4f8;
}
.lro-table thead th {
    font-size: .8rem;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: #6c757d;
    border-bottom: 0;
}
.lro-table td {
    vertical-align: middle;
    border-top: 1px solid #eceff3;
    font-size: .92rem;
}

/* Document type badges (icons) */
.lro-doc-icon {
    font-size: .75rem;
    font-weight: 600;
    padding: .2rem .55rem;
    border-radius: 999px;
    display: inline-block;
}
.lro-doc-pdf {
    background: #ffdddd;
    color: #a80000;
}
.lro-doc-img {
    background: #d7ffd7;
    color: #077a0a;
}
.lro-doc-any {
    background: #e0f0ff;
    color: #0053a3;
}

/* Status badges */
.lro-badge {
    padding: .25rem .8rem;
    border-radius: 20px;
    font-size: .75rem;
}
.lro-badge-rej { background:#ffe1e1; color:#c60000; }
.lro-badge-pend { background:#fff4c2; color:#8a6d00; }
.lro-badge-ok { background:#e1ffe8; color:#0a7a2f; }
.lro-badge-none { background:#e4e8ef; color:#4d5566; }

/* Upload cell */
.lro-upload-cell {
    min-width: 260px;
}

/* Progress */
.lro-progress {
    height: 4px;
    margin-top: .35rem;
    background: #e1e6ef;
    border-radius: 50px;
    overflow: hidden;
}
.lro-progress-bar {
    height: 100%;
    width: 0%;
    background: linear-gradient(90deg,#00c46b,#198754);
    transition: width .2s ease;
}

/* Inline message under progress */
.lro-inline-msg {
    font-size: .8rem;
    margin-top: .4rem;
}

/* Global toast bottom-right */
#lro-toast-container {
    position: fixed;
    right: 1.25rem;
    bottom: 1.25rem;
    z-index: 9999;
}
.lro-toast {
    background: #198754;
    color: #fff;
    padding: .8rem 1rem;
    border-radius: .5rem;
    margin-top: .6rem;
    box-shadow: 0 6px 18px rgba(0,0,0,.2);
    opacity: 0;
    transform: translateY(10px);
    transition: all .25s ease;
    font-size: .9rem;
}
.lro-toast-error { background:#dc3545; }
.lro-toast-show { opacity:1; transform:translateY(0); }

/* Tidy up file input so it doesn¡¯t force horizontal scroll */
.lro-upload-cell input[type="file"] {
    max-width: 100%;
}
</style>

<div class="lro-upload-shell">

    <div class="d-flex justify-content-between flex-wrap">
        <div>
            <div class="lro-upload-title">
                {l s='Upload Documents' mod='lrofileupload'}
            </div>
            <div class="lro-upload-sub mt-1">
                {l s='Only rejected or not-yet-uploaded documents are shown here. Approved and pending documents are hidden.' mod='lrofileupload'}
            </div>
        </div>
        <div class="small text-muted mt-3 mt-md-0">
            <strong>{$max_mb|intval}MB</strong> ¡ª PDF / JPG / PNG
        </div>
    </div>

    <div class="lro-divider"></div>

    {if not $cards || !count($cards)}
        <div class="alert alert-info mb-0">
            {l s='You currently have no documents to upload.' mod='lrofileupload'}
        </div>
    {else}

    {assign var=cards_count value=$cards|@count}

    {foreach from=$cards item=card name=groupsLoop}
    <!-- ONE FULL-WIDTH COLLAPSIBLE CARD PER GROUP -->
    {assign var=body_id value="lro-card-body-`$smarty.foreach.groupsLoop.iteration`"}
    <div class="lro-card" data-group-card="1">
        <div class="lro-card-header"
             data-target="#{$body_id|escape:'html'}"
             data-open="{if $cards_count > 1}0{else}1{/if}">
            <div>
                <div class="lro-card-title">{$card.name|escape:'html'}</div>
                {if $card.desc}
                    <div class="lro-card-sub">{$card.desc|escape:'html'}</div>
                {/if}
            </div>
            <div class="lro-collapse-indicator">
                {if $cards_count > 1}+{else}-{/if}
            </div>
        </div>

        <div id="{$body_id|escape:'html'}"
             class="lro-card-body-wrap{if $cards_count > 1} d-none{/if}">
            <div class="card-body p-0">
                <table class="table lro-table mb-0">
                    <thead>
                        <tr>
                            <th>{l s='Document' mod='lrofileupload'}</th>
                            <th>{l s='Details' mod='lrofileupload'}</th>
                            <th>{l s='Status' mod='lrofileupload'}</th>
                            <th>{l s='Upload' mod='lrofileupload'}</th>
                        </tr>
                    </thead>

                    <tbody>
                    {foreach from=$card.requirements item=req}
                    <tr data-group="{$card.id_group|intval}" data-req="{$req.id_requirement|intval}">
                        <td>
                            <strong>{$req.title|escape:'html'}</strong>
                            {if $req.required}<span class="text-danger">*</span>{/if}

                            <div class="mt-1">
                                {if $req.file_type=='pdf'}
                                    <span class="lro-doc-icon lro-doc-pdf">PDF</span>
                                {elseif $req.file_type=='image'}
                                    <span class="lro-doc-icon lro-doc-img">{l s='IMG' mod='lrofileupload'}</span>
                                {else}
                                    <span class="lro-doc-icon lro-doc-any">PDF / IMG</span>
                                {/if}
                            </div>
                        </td>

                        <td>
                            {if $req.description}
                            <div class="small text-muted">{$req.description|escape:'html'}</div>
                            {/if}
                            {if $req.last_reason && $req.last_status=='rejected'}
                            <div class="small text-danger mt-1">
                                <strong>{l s='Reason:' mod='lrofileupload'}</strong>
                                {$req.last_reason|escape:'html'}
                            </div>
                            {/if}
                        </td>

                        <td>
                            {assign var=status value=$req.last_status|lower}
                            {if $status=='rejected'}
                                <span class="lro-badge lro-badge-rej">{l s='Rejected' mod='lrofileupload'}</span>
                            {elseif $status=='pending'}
                                <span class="lro-badge lro-badge-pend">{l s='Pending' mod='lrofileupload'}</span>
                            {elseif $status=='approved'}
                                <span class="lro-badge lro-badge-ok">{l s='Approved' mod='lrofileupload'}</span>
                            {else}
                                <span class="lro-badge lro-badge-none">{l s='Not uploaded' mod='lrofileupload'}</span>
                            {/if}
                        </td>

                        <td>
                            <div class="lro-upload-cell">
                                <input class="form-control-file lro-file-input mb-1"
                                       type="file"
                                       accept=".pdf,image/*" />

                                <div class="d-flex justify-content-between">
                                    <small class="text-muted">
                                        {l s='Max' mod='lrofileupload'} {$max_mb|intval}MB
                                    </small>

                                    <button type="button"
                                            class="btn btn-sm btn-primary lro-upload-btn">
                                        {l s='Upload' mod='lrofileupload'}
                                    </button>
                                </div>

                                <div class="lro-progress d-none">
                                    <div class="lro-progress-bar"></div>
                                </div>

                                <div class="lro-inline-msg text-danger d-none"></div>
                            </div>
                        </td>
                    </tr>
                    {/foreach}
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    {/foreach}

    {/if}
</div>

{if $cards && count($cards)}
{* Localised strings for JS *}
{capture name='lro_msg_success'}{l s='Your file has been uploaded securely and is awaiting review.' mod='lrofileupload'}{/capture}
{capture name='lro_msg_error'}{l s='Upload failed.' mod='lrofileupload'}{/capture}
{capture name='lro_msg_network'}{l s='Upload failed due to a network error.' mod='lrofileupload'}{/capture}
{capture name='lro_msg_choose'}{l s='Please choose a file to upload.' mod='lrofileupload'}{/capture}
{capture name='lro_msg_too_big'}{l s='File is too large.' mod='lrofileupload'}{/capture}

<script>
(function(){
    var uploadUrl = '{$upload_url|escape:'javascript'}';
    var maxBytes = {$max_mb|intval} * 1024 * 1024;

    var MSG_SUCCESS = "{$smarty.capture.lro_msg_success|escape:'javascript'}";
    var MSG_ERROR   = "{$smarty.capture.lro_msg_error|escape:'javascript'}";
    var MSG_NET     = "{$smarty.capture.lro_msg_network|escape:'javascript'}";
    var MSG_CHOOSE  = "{$smarty.capture.lro_msg_choose|escape:'javascript'}";
    var MSG_BIG     = "{$smarty.capture.lro_msg_too_big|escape:'javascript'}";

    function rowOf(el){
        while (el && el.tagName !== 'TR') {
            el = el.parentNode;
        }
        return el;
    }

    /* ---------- Toast helpers ---------- */
    function ensureToastContainer(){
        var c = document.getElementById('lro-toast-container');
        if (!c) {
            c = document.createElement('div');
            c.id = 'lro-toast-container';
            document.body.appendChild(c);
        }
        return c;
    }

    function toast(msg, isError){
        var c = ensureToastContainer();
        var t = document.createElement('div');
        t.className = 'lro-toast' + (isError ? ' lro-toast-error' : '');
        t.textContent = msg || '';
        c.appendChild(t);

        setTimeout(function(){
            t.classList.add('lro-toast-show');
        }, 10);

        setTimeout(function(){
            t.classList.remove('lro-toast-show');
            setTimeout(function(){
                if (t.parentNode) { t.parentNode.removeChild(t); }
            }, 250);
        }, 3500);
    }

    /* ---------- Upload logic (auto + button, with progress) ---------- */
    function doUpload(row, fileInp, btn){
        if (!row || !fileInp) { return; }

        var msgBox = row.querySelector('.lro-inline-msg');
        if (msgBox) {
            msgBox.classList.add('d-none');
            msgBox.textContent = '';
            msgBox.classList.remove('text-success', 'text-danger');
        }

        if (!fileInp.files || !fileInp.files.length) {
            if (msgBox) {
                msgBox.textContent = MSG_CHOOSE;
                msgBox.classList.remove('d-none');
                msgBox.classList.add('text-danger');
            }
            toast(MSG_CHOOSE, true);
            return;
        }

        var f = fileInp.files[0];
        if (f.size <= 0 || f.size > maxBytes) {
            var tooBigMsg = MSG_BIG + ' ' + (maxBytes / (1024 * 1024)) + 'MB.';
            if (msgBox) {
                msgBox.textContent = tooBigMsg;
                msgBox.classList.remove('d-none');
                msgBox.classList.add('text-danger');
            }
            toast(tooBigMsg, true);
            return;
        }

        var idg = row.getAttribute('data-group');
        var idr = row.getAttribute('data-req');

        var prog = row.querySelector('.lro-progress');
        var bar  = row.querySelector('.lro-progress-bar');
        if (prog && bar) {
            prog.classList.remove('d-none');
            bar.style.width = '0%';
        }

        if (btn) {
            btn.disabled = true;
            btn.textContent = '...';
        }
        fileInp.disabled = true;

        var fd = new FormData();
        fd.append('id_group', idg);
        fd.append('id_requirement', idr);
        fd.append('file', f);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', uploadUrl, true);

        xhr.upload.onprogress = function(e){
            if (!e.lengthComputable || !bar) return;
            var p = (e.loaded / e.total) * 100;
            bar.style.width = p + '%';
        };

        xhr.onload = function(){
            var r = {};
            try { r = xhr.responseText ? JSON.parse(xhr.responseText) : {}; } catch(e){}

            if (xhr.status >= 200 && xhr.status < 300 && r && r.success) {
                if (msgBox) {
                    msgBox.textContent = r.message || MSG_SUCCESS;
                    msgBox.classList.remove('d-none');
                    msgBox.classList.remove('text-danger');
                    msgBox.classList.add('text-success');
                }
                toast(r.message || MSG_SUCCESS, false);
                setTimeout(function(){ window.location.reload(); }, 700);
            } else {
                var msg = (r && r.message) ? r.message : MSG_ERROR;
                if (msgBox) {
                    msgBox.textContent = msg;
                    msgBox.classList.remove('d-none');
                    msgBox.classList.remove('text-success');
                    msgBox.classList.add('text-danger');
                }
                toast(msg, true);
                if (bar) { bar.style.width = '0%'; }
                if (prog) { prog.classList.add('d-none'); }
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = "{l s='Upload' mod='lrofileupload'}";
                }
                fileInp.disabled = false;
            }
        };

        xhr.onerror = function(){
            if (msgBox) {
                msgBox.textContent = MSG_NET;
                msgBox.classList.remove('d-none');
                msgBox.classList.remove('text-success');
                msgBox.classList.add('text-danger');
            }
            toast(MSG_NET, true);
            if (bar) { bar.style.width = '0%'; }
            if (prog) { prog.classList.add('d-none'); }
            if (btn) {
                btn.disabled = false;
                btn.textContent = "{l s='Upload' mod='lrofileupload'}";
            }
            fileInp.disabled = false;
        };

        xhr.send(fd);
    }

    /* ---------- Event hooks ---------- */

    // Manual upload button (failsafe)
    document.addEventListener('click', function(ev){
        if (!ev.target.classList.contains('lro-upload-btn')) {
            return;
        }
        ev.preventDefault();
        var row = rowOf(ev.target);
        if (!row) return;
        var fileInp = row.querySelector('.lro-file-input');
        doUpload(row, fileInp, ev.target);
    });

    // Auto-upload when a file is chosen
    document.addEventListener('change', function(ev){
        if (!ev.target.classList.contains('lro-file-input')) {
            return;
        }
        var row = rowOf(ev.target);
        if (!row) return;
        var btn = row.querySelector('.lro-upload-btn');
        doUpload(row, ev.target, btn);
    });

    /* ---------- Collapsible group headers ---------- */
    document.querySelectorAll('.lro-card-header').forEach(function(header){
        var targetSelector = header.getAttribute('data-target');
        var body   = targetSelector ? document.querySelector(targetSelector) : null;
        var icon   = header.querySelector('.lro-collapse-indicator');

        // For single-group case, body is already visible; for multi-group we start collapsed.
        if (header.getAttribute('data-open') === '1') {
            if (body) body.classList.remove('d-none');
            if (icon) icon.textContent = '-';
        } else {
            if (body) body.classList.add('d-none');
            if (icon) icon.textContent = '+';
        }

        header.addEventListener('click', function(e){
            // Do not toggle if clicking somewhere that might be interactive inside header (currently none).
            if (!body) return;
            var open = header.getAttribute('data-open') === '1';

            if (open) {
                body.classList.add('d-none');
                header.setAttribute('data-open', '0');
                if (icon) icon.textContent = '+';
            } else {
                body.classList.remove('d-none');
                header.setAttribute('data-open', '1');
                if (icon) icon.textContent = '-';
            }
        });
    });

})();
</script>
{/if}

{/block}
