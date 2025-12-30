{extends file='page.tpl'}

{block name='page_title'}{l s='Profile: Document Upload & Records' mod='lrofileupload'}{/block}

{block name='page_content'}
<div class="container mt-4">
  <h2>{l s='Upload a Document' mod='lrofileupload'}</h2>
  {if isset($errors) && $errors|@count > 0}
    <div class="alert alert-danger">
      <ul>
        {foreach from=$errors item=err}
          <li>{$err}</li>
        {/foreach}
      </ul>
    </div>
  {/if}
  {if isset($success) && $success}
    <div class="alert alert-success">{l s='File(s) uploaded successfully!' mod='lrofileupload'}</div>
  {/if}
  {include file='module:lrofileupload/views/templates/front/upload_form.tpl'}

  <h3 class="mt-5">{l s='Your Uploaded Documents' mod='lrofileupload'}</h3>
  {if $uploaded_files|@count > 0}
    <table class="table table-bordered table-hover align-middle">
      <thead class="table-light">
        <tr>
          <th>{l s='File Name' mod='lrofileupload'}</th>
          <th>{l s='Type' mod='lrofileupload'}</th>
          <th>{l s='Status' mod='lrofileupload'}</th>
          <th>{l s='Date Uploaded' mod='lrofileupload'}</th>
          <th>{l s='Download' mod='lrofileupload'}</th>
        </tr>
      </thead>
      <tbody>
        {foreach from=$uploaded_files item=file}
        <tr>
          <td>{$file.original_name|escape}</td>
          <td>{$file.reason_text|escape}</td>
          <td>
            {if $file.status == 'pending'}
              <span class="badge bg-warning text-dark">{l s='Pending' mod='lrofileupload'}</span>
            {elseif $file.status == 'approved'}
              <span class="badge bg-success">{l s='Approved' mod='lrofileupload'}</span>
            {elseif $file.status == 'rejected'}
              <span class="badge bg-danger">{l s='Rejected' mod='lrofileupload'}</span>
            {/if}
          </td>
          <td>{$file.date_add|date_format:"%Y-%m-%d %H:%M"}</td>
          <td>
            <a href="{$file.download_link}" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener noreferrer">
              {l s='Download' mod='lrofileupload'}
            </a>
          </td>
        </tr>
        {/foreach}
      </tbody>
    </table>
  {else}
    <p>{l s='No documents uploaded yet.' mod='lrofileupload'}</p>
  {/if}
  {include file='module:lrofileupload/views/templates/front/upload_result.tpl'}
</div>
{/block} 