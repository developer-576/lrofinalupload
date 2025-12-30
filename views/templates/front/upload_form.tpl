{extends file="page.tpl"}

{block name='page_content'}
<h1>Upload Files for {$group.group_name}</h1>
<p>{$group.description}</p>

<form id="uploadForm" method="post" enctype="multipart/form-data">
    <table class="table">
        <thead>
            <tr>
                <th>Document</th>
                <th>Type</th>
                <th>Required</th>
                <th>Upload</th>
            </tr>
        </thead>
        <tbody>
        {foreach from=$requirements item=req}
            <tr>
                <td>{$req.document_name}</td>
                <td>{$req.file_type|upper}</td>
                <td>{if $req.required}<strong style="color:red;">Yes</strong>{else}No{/if}</td>
                <td>
                    <input type="file" name="files[{$req.id_requirement}]" accept="{$req.file_type}">
                </td>
            </tr>
        {/foreach}
        </tbody>
    </table>
    <button type="submit" class="btn btn-primary">Upload</button>
</form>

<div id="uploadResult"></div>

<script>
document.getElementById('uploadForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const form = new FormData(this);
    fetch('{$upload_action}', {
        method: 'POST',
        body: form
    })
    .then(res => res.json())
    .then(data => {
        document.getElementById('uploadResult').innerHTML = '<p>' + data.message + '</p>';
    })
    .catch(err => {
        document.getElementById('uploadResult').innerHTML = '<p style="color:red;">Upload failed.</p>';
    });
});
</script>
{/block}
