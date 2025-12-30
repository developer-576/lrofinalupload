<h2>My Document Uploads Overview</h2>

{if $uploads}
<table class="table table-bordered">
    <thead>
        <tr>
            <th>Group</th>
            <th>Requirement</th>
            <th>Type</th>
            <th>Status</th>
            <th>Rejection Reason</th>
            <th>File</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        {foreach from=$uploads item=upload}
            <tr>
                <td>{$upload.group_name|escape}</td>
                <td>{$upload.requirement_name|escape}</td>
                <td>{$upload.file_type|escape}</td>
                <td>{$upload.status|default:'Pending'}</td>
                <td>{$upload.rejection_reason|default:'-'}</td>
                <td>
                    {if $upload.file_path}
                        <a href="{$module_dir}uploads/{$upload.file_path|escape}" target="_blank">View</a>
                    {else}
                        -
                    {/if}
                </td>
                <td>
                    {if $upload.status == 'Rejected'}
                        <form method="post" enctype="multipart/form-data" action="{$module_dir}uploadhandler.php" onsubmit="return uploadFile(this);">
                            <input type="hidden" name="id_requirement" value="{$upload.id_requirement}">
                            <input type="file" name="upload_file" accept="{$upload.file_type}" required>
                            <button type="submit" class="btn btn-warning btn-sm mt-1">Re-upload</button>
                        </form>
                    {elseif $upload.status == 'Pending'}
                        <form method="post" enctype="multipart/form-data" action="{$module_dir}uploadhandler.php" onsubmit="return uploadFile(this);">
                            <input type="hidden" name="id_requirement" value="{$upload.id_requirement}">
                            <input type="file" name="upload_file" accept="{$upload.file_type}" required>
                            <button type="submit" class="btn btn-success btn-sm mt-1">Upload</button>
                        </form>
                    {else}
                        <span class="text-success">Approved</span>
                    {/if}
                </td>
            </tr>
        {/foreach}
    </tbody>
</table>
{else}
    <p>No uploads available yet.</p>
{/if}

<script>
function uploadFile(formElement) {
    const formData = new FormData(formElement);
    const submitBtn = formElement.querySelector('button');

    submitBtn.disabled = true;

    fetch(formElement.action, {
        method: 'POST',
        body: formData
    }).then(response => response.json()).then(data => {
        alert(data.message);
        if (data.status === 'success') {
            window.location.reload();
        }
    }).catch(error => {
        alert('Upload failed');
    }).finally(() => {
        submitBtn.disabled = false;
    });

    return false;
}
</script>
