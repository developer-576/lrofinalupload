{* lro_recent_docs = { recent:[], approved:[], rejected:[] } *}

{literal}
<style>
  .lro-card{max-width:980px;margin:1rem 0;padding:0;border:1px solid #e5e7eb;border-radius:10px;background:#fff;overflow:hidden;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}
  .lro-head{display:flex;gap:.5rem;padding:.8rem 1rem;background:#f8fafc;border-bottom:1px solid #e5e7eb}
  .lro-tab{cursor:pointer;padding:.35rem .7rem;border-radius:999px;font-weight:700;font-size:.9rem;color:#1f2937}
  .lro-tab[data-active="1"]{background:#e5edff;color:#111827}
  .lro-body{padding:.6rem 1rem}
  .lro-row{display:flex;justify-content:space-between;gap:1rem;padding:.45rem 0;border-bottom:1px dashed #eef2f7}
  .lro-row:last-child{border-bottom:0}
  .lro-left{min-width:0}
  .lro-file{font-weight:600;word-break:break-word}
  .lro-sub{color:#6b7280;font-size:.85rem}
  .lro-pill{align-self:center;padding:.15rem .5rem;border-radius:999px;font-size:.8rem;font-weight:700}
  .lro-pill--ok{background:#ecfdf5;color:#065f46}
  .lro-pill--no{background:#fef2f2;color:#991b1b}
  .lro-empty{color:#6b7280;padding:.5rem 0}
</style>
<script>
  document.addEventListener('DOMContentLoaded', function(){
    var box = document.querySelector('.lro-card');
    if(!box) return;
    function show(which){
      box.querySelectorAll('[data-pane]').forEach(p => p.style.display = (p.dataset.pane===which)?'block':'none');
      box.querySelectorAll('.lro-tab').forEach(t => t.dataset.active = (t.dataset.pane===which)?'1':'0');
    }
    box.querySelectorAll('.lro-tab').forEach(t => t.addEventListener('click', () => show(t.dataset.pane)));
    show('recent');
  });
</script>
{/literal}

{assign var=R value=$lro_recent_docs.recent|default:[]}
{assign var=A value=$lro_recent_docs.approved|default:[]}
{assign var=J value=$lro_recent_docs.rejected|default:[]}

<div class="lro-card">
  <div class="lro-head">
    <div class="lro-tab" data-pane="recent">Recently uploaded ({$R|count})</div>
    <div class="lro-tab" data-pane="approved">Approved ({$A|count})</div>
    <div class="lro-tab" data-pane="rejected">Rejected ({$J|count})</div>
  </div>

  <div class="lro-body">
    <div data-pane="recent">
      {if $R|count == 0}
        <div class="lro-empty">No recent uploads yet.</div>
      {else}
        {foreach from=$R item=i}
          <div class="lro-row">
            <div class="lro-left">
              <div class="lro-file">{$i.file_name|escape:'html'}</div>
              <div class="lro-sub">Group: {$i.group_label|escape:'html'}{if $i.uploaded_at} • {$i.uploaded_at|escape:'html'}{/if}</div>
            </div>
            {if $i.status}
              {if $i.status|lower=='approved'}
                <div class="lro-pill lro-pill--ok">approved</div>
              {elseif $i.status|lower=='rejected'}
                <div class="lro-pill lro-pill--no">rejected</div>
              {else}
                <div class="lro-pill" style="background:#fff7ed;color:#9a3412">{$i.status|escape:'html'}</div>
              {/if}
            {/if}
          </div>
        {/foreach}
      {/if}
    </div>

    <div data-pane="approved" style="display:none">
      {if $A|count == 0}
        <div class="lro-empty">No approved files yet.</div>
      {else}
        {foreach from=$A item=i}
          <div class="lro-row">
            <div class="lro-left">
              <div class="lro-file">{$i.file_name|escape:'html'}</div>
              <div class="lro-sub">Group: {$i.group_label|escape:'html'}{if $i.uploaded_at} • {$i.uploaded_at|escape:'html'}{/if}</div>
            </div>
            <div class="lro-pill lro-pill--ok">approved</div>
          </div>
        {/foreach}
      {/if}
    </div>

    <div data-pane="rejected" style="display:none">
      {if $J|count == 0}
        <div class="lro-empty">No rejected files.</div>
      {else}
        {foreach from=$J item=i}
          <div class="lro-row">
            <div class="lro-left">
              <div class="lro-file">{$i.file_name|escape:'html'}</div>
              <div class="lro-sub">Group: {$i.group_label|escape:'html'}{if $i.uploaded_at} • {$i.uploaded_at|escape:'html'}{/if}</div>
            </div>
            <div class="lro-pill lro-pill--no">rejected</div>
          </div>
        {/foreach}
      {/if}
    </div>
  </div>
</div>
