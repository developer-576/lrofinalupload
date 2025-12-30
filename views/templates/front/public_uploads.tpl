<h3>📂 Uploaded Files</h3>

{if !$uploads}
  <p>No uploaded files found.</p>
{else}
  <table class="table table-bordered">
    <thead>
      <tr>
        <th>File Name</th>
        <th>Type</th>
        <th>Status</th>
        <th>Uploaded</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
      {foreach from=$uploads item=upload}
        <tr>
          <td>{$upload.file_name|escape:'htmlall':'UTF-8'}</td>
          <td>{$upload.file_type}</td>
          <td>
            {if $upload.status == 'approved'}
              <span class="badge bg-success">Approved</span>
            {elseif $upload.status == 'rejected'}
              <span class="badge bg-danger" title="{$upload.rejection_reason}">Rejected</span>
            {else}
              <span class="badge bg-secondary">Pending</span>
            {/if}
          </td>
          <td>{$upload.date_uploaded}</td>
          <td>
            {if $upload.downloadable}
              <a href="{$upload.file_url}" class="btn btn-sm btn-success" target="_blank">Download</a>
            {else}
              <span class="text-muted">N/A</span>
            {/if}
          </td>
        </tr>
      {/foreach}
    </tbody>
  </table>
{/if}
