{*
  Upload history
  $uploads[] rows have: file_name, group_label, is_recording (bool), status, reason, uploaded_at, can_download, download_url
  $recording_ref is the latest order reference for this customer (string|null)
*}

{literal}
<style>
  .lro-wrap{max-width:980px;margin:2rem auto;padding:0 1rem;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}
  .lro-title{font-size:1.35rem;font-weight:700;margin:0 0 1rem}
  .lro-table{width:100%;border-collapse:collapse;font-size:.95rem;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden}
  .lro-table thead th{background:#f8fafc;text-align:left;font-weight:600;padding:.65rem .75rem;border-bottom:1px solid #e5e7eb;white-space:nowrap}
  .lro-table tbody td{padding:.65rem .75rem;border-bottom:1px solid #f1f5f9;vertical-align:middle}
  .lro-pill{display:inline-block;padding:.15rem .5rem;border-radius:999px;font-size:.85rem;font-weight:700}
  .lro-pill--ok{background:#ecfdf5;color:#065f46}
  .lro-pill--no{background:#fef2f2;color:#991b1b}
  .lro-btn{display:inline-flex;align-items:center;gap:.4rem;border:1px solid #2563eb;background:#3b82f6;color:#fff;padding:.35rem .6rem;border-radius:6px;text-decoration:none;font-weight:600;font-size:.9rem}
  .lro-btn[aria-disabled="true"]{opacity:.45;pointer-events:none}
  .lro-refrow{margin-top:.25rem;font-size:.85rem;color:#374151}
  .lro-ref__badge{display:inline-block;background:#eef2ff;color:#3730a3;border-radius:999px;padding:.15rem .5rem;font-weight:700}
  .text-break{word-break:break-word}
</style>
{/literal}

<div class="lro-wrap">
  <h2 class="lro-title">Your Uploaded Files</h2>

  {if empty($uploads)}
    <p>No files uploaded yet.</p>
  {else}
    <table class="lro-table">
      <thead>
        <tr>
          <th>File</th>
          <th>Group</th>
          <th>Status</th>
          <th>Reason</th>
          <th>Uploaded</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      {foreach from=$uploads item=u}
        <tr>
          <td class="text-break">{$u.file_name|escape:'html'}</td>
          <td>
            <div>{$u.group_label|escape:'html'}</div>
            {if $u.is_recording && $recording_ref}
              <div class="lro-refrow">
                Recording #: <span class="lro-ref__badge">{$recording_ref|escape:'html'}</span>
              </div>
            {/if}
          </td>
          <td>
            {if $u.status|lower == 'approved'}
              <span class="lro-pill lro-pill--ok">approved</span>
            {elseif $u.status|lower == 'rejected'}
              <span class="lro-pill lro-pill--no">rejected</span>
            {else}
              <span class="lro-pill" style="background:#fff7ed;color:#9a3412">{$u.status|escape:'html'}</span>
            {/if}
          </td>
          <td class="text-break">{$u.reason|default:''|escape:'html'}</td>
          <td>{$u.uploaded_at|escape:'html'}</td>
          <td>
            {if $u.can_download && $u.download_url}
              <a class="lro-btn" href="{$u.download_url|escape:'html'}">&#128229; Download</a>
            {else}
              <a class="lro-btn" aria-disabled="true" href="#">&#128229; Download</a>
            {/if}
          </td>
        </tr>
      {/foreach}
      </tbody>
    </table>
  {/if}
</div>
