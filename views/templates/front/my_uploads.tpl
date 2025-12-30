<h2>My Upload Summary</h2>

{if $allUploads}
<table class="table table-bordered">
    <thead>
        <tr>
            <th>Group</th>
            <th>Requirement</th>
            <th>Type</th>
            <th>Status</th>
            <th>Rejection Reason</th>
            <th>Uploaded At</th>
            <th>File</th>
        </tr>
    </thead>
    <tbody>
        {foreach from=$allUploads item=upload}
            <tr>
                <td>{$upload.group_name|escape}</td>
                <td>{$upload.requirement_name|escape}</td>
                <td>{$upload.file_type|escape}</td>
                <td>{$upload.status|default:'Pending'}</td>
                <td>{$upload.rejection_reason|default:'-'}</td>
                <td>{$upload.uploaded_at|escape}</td>
                <td>
                    {if $upload.file_path}
                        <a href="{$module_dir}uploads/{$upload.file_path|escape}" target="_blank">View</a>
                    {else}
                        -
                    {/if}
                </td>
            </tr>
        {/foreach}
    </tbody>
</table>
{else}
    <p>No uploads recorded yet.</p>
{/if}
