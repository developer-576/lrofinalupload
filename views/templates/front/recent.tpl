{capture name=path}{$page_title|default:'Your Uploaded Files'|escape:'html'}{/capture}

{literal}
<style>
  .lro-wrap{max-width:980px;margin:1.5rem auto;padding:0 1rem;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}
  .lro-tabs{display:flex;gap:.5rem;margin-bottom:.75rem}
  .lro-tab{border:1px solid #e5e7eb;background:#fff;border-radius:999px;padding:.4rem .8rem;cursor:pointer;font-weight:700}
  .lro-tab[data-active="1"]{background:#eef2ff}
  .lro-card{border:1px solid #e5e7eb;border-radius:12px;background:#fff;overflow:hidden}
  .lro-row{display:flex;justify-content:space-between;gap:1rem;padding:.75rem 1rem;border-bottom:1px dashed #eef2f7}
  .lro-row:last-child{border-bottom:0}
  .lro-file{font-weight:600;word-break:break-word}
  .lro-sub{color:#6b7280;font-size:.85rem}
  .pill{align-self:center;padding:.2rem .5rem;border-radius:999px;font-size:.8rem;font-weight:700;white-space:nowrap}
  .ok{background:#ecfdf5;color:#065f46}
  .no{background:#fef2f2;color:#991b1b}
  .pending{background:#fff7ed;color:#9a3412}
  .btn-slim{display:inline-block;padding:.35rem .6rem;border-radius:8px;border:1px solid #d1d5db;background:#fff;font-weight:600}
</style>
<script>
  document.addEventListener('DOMContentLoaded',function(){
    var tabs = document.querySelectorAll('.lro-tab');
    var panes = document.querySelectorAll('[data-pane]');
    function show(k){
      tabs.forEach(t=>t.dataset.active = (t.dataset.pane===k)?'1':'0');
      panes.forEach(p=>p.style.display = (p.dataset.pane===k)?'block':'none');
    }
    tabs.forEach(t=>t.addEventListener('click',()=>show(t.dataset.pane)));
    show('recent');
  });
</script>
{/literal}

<div class="lro-wrap">
  <h1 class="h1 page-heading">{$page_title|escape:'html'}</h1>

  <div class="lro-tabs">
    {assign var=R value=$lro_recent_docs.recent|default:[]}
    {assign var=A value=$lro_recent_docs.approved|default:[]}
    {assign var=J value=$lro_recent_docs.rejected|default:[]}
    <div class="lro-tab" data-pane="recent">Recently uploaded ({$R|count})</div>
    <div class="lro-tab" data-pane="approved">Approved ({$A|count})</div>
    <div class="lro-tab" data-pane="rejected">Rejected ({$J|count})</div>
  </div>

  <div class="lro-card">
    <div data-pane="recent">
      {if $R|count == 0}
        <div class="lro-row"><div class="lro-file">No recent uploads.</div></div>
      {else}
        {foreach from=$R item=i}
          <div class="lro-row">
            <div>
              <div class="lro-file">{$i.file_name|escape:'html'}</div>
              <div class="lro-sub">Group: {$i.group_label|escape:'html'}{if $i.uploaded_at} • {$i.uploaded_at|escape:'html'}{/if}</div>
            </div>
            {if $i.status}
              {assign var=st value=$i.status|lower}
              <div class="pill {if $st=='approved'}ok{elseif $st=='rejected'}no{else}pending{/if}">{$i.status|escape:'html'}</div>
            {/if}
          </div>
        {/foreach}
      {/if}
    </div>

    <div data-pane="approved" style="display:none">
      {if $A|count == 0}
        <div class="lro-row"><div class="lro-file">No approved files yet.</div></div>
      {else}
        {foreach from=$A item=i}
          <div class="lro-row">
            <div>
              <div class="lro-file">{$i.file_name|escape:'html'}</div>
              <div class="lro-sub">Group: {$i.group_label|escape:'html'}{if $i.uploaded_at} • {$i.uploaded_at|escape:'html'}{/if}</div>
            </div>
            <div style="display:flex;gap:.5rem;align-items:center">
              <div class="pill ok">approved</div>
              {if $i.group_id !== null}
                <a class="btn-slim"
                   href="{$link->getModuleLink('lrofileupload','download', ['file'=>$i.file_name, 'g'=>$i.group_id])|escape:'html'}">
                   Download
                </a>
              {/if}
            </div>
          </div>
        {/foreach}
      {/if}
    </div>

    <div data-pane="rejected" style="display:none">
      {if $J|count == 0}
        <div class="lro-row"><div class="lro-file">No rejected files.</div></div>
      {else}
        {foreach from=$J item=i}
          <div class="lro-row">
            <div>
              <div class="lro-file">{$i.file_name|escape:'html'}</div>
              <div class="lro-sub">
                Group: {$i.group_label|escape:'html'}{if $i.uploaded_at} • {$i.uploaded_at|escape:'html'}{/if}
                {if $i.reason} • Reason: {$i.reason|escape:'html'}{/if}
              </div>
            </div>
            <div class="pill no">rejected</div>
          </div>
        {/foreach}
      {/if}
    </div>
  </div>
</div>
