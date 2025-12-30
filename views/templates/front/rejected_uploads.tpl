<h2>My Rejected Uploads</h2>

{if $rejectedUploads}
<table class="table table-bordered">
    <thead>
        <tr>
            <th>Group</th>
            <th>Requirement</th>
            <th>Rejection Reason</th>
            <th>Previous File</th>
            <th>Re-upload</th>
        </tr>
    </thead>
    <tbody>
        {foreach from=$rejectedUploads item=upload}
            <tr>
                <td>{$upload.group_name|escape}</td>
                <td>{$upload.requirement_name|escape} ({$upload.file_type|escape})</td>
                <td>{$upload.rejection_reason|default:'-'}</td>
                <td>
                    {if $upload.file_path}
                        <a href="{$module_dir}uploads/{$upload.file_path|escape}" target="_blank">View</a>
                    {else}
                        -
                    {/if}
                </td>
                <td>
                    <form method="post" enctype="multipart/form-data" action="{$module_dir}uploadhandler.php" onsubmit="return uploadFile(this);">
                        <input type="hidden" name="id_requirement" value="{$upload.id_requirement}">
                        <input type="file" name="upload_file" accept="{$upload.file_type}" required>
                        <button type="submit" class="btn btn-sm btn-warning mt-1">Re-upload</button>
                        <div class="progress mt-1" style="height: 5px; display: none;">
                            <div class="progress-bar" style="width:0%"></div>
                        </div>
                    </form>
                </td>
            </tr>
        {/foreach}
    </tbody>
</table>
{else}
    <p>No rejected uploads found.</p>
{/if}

<script>
function uploadFile(formElement) {
    const formData = new FormData(formElement);
    const progressBar = formElement.querySelector('.progress-bar');
    const submitBtn = formElement.querySelector('button');

    submitBtn.disabled = true;
    progressBar.style.width = '0%';
    progressBar.parentElement.style.display = 'block';

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
