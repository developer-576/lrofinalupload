<style>
.upload-table-container {
    width: 100%;
    margin: 30px auto;
    font-family: Arial, sans-serif;
    background: #f7f7f7;
    border-radius: 8px;
    box-shadow: 0 2px 8px #e0e0e0;
    padding: 24px;
}
.upload-table {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
    margin-bottom: 24px;
    font-size: 15px;
}
.upload-table th, .upload-table td {
    border: 1px solid #d0d0d0;
    padding: 10px 8px;
    text-align: left;
}
.upload-table th {
    background: #e9f1fb;
    font-weight: bold;
}
.upload-table tr:nth-child(even) {
    background: #f7faff;
}
.upload-table tr:nth-child(odd) {
    background: #fff;
}
.status-accepted {
    color: #0a8f36;
    font-weight: bold;
    text-transform: capitalize;
}
.status-pending {
    color: #d49f00;
    font-weight: bold;
    text-transform: capitalize;
}
.status-rejected {
    color: #e53935;
    font-weight: bold;
    text-transform: capitalize;
}
.action-btn {
    padding: 5px 16px;
    background: #e53935;
    color: #fff;
    border: none;
    border-radius: 4px;
    font-size: 14px;
    cursor: pointer;
    text-decoration: none;
    transition: background 0.2s;
    margin: 0;
    display: inline-block;
}
.action-btn:hover {
    background: #b71c1c;
}
.upload-link {
    color: #1976d2;
    text-decoration: underline;
    cursor: pointer;
}
.upload-link:hover {
    text-decoration: none;
}
.section-title {
    background: #e3e3e3;
    font-weight: bold;
    padding: 8px 12px;
    border-radius: 4px 4px 0 0;
    margin-top: 24px;
    margin-bottom: 0;
    font-size: 16px;
    letter-spacing: 0.5px;
}
</style>

<div class="upload-table-container">
    <div class="section-title">Your Uploaded Documents</div>
    <table class="upload-table">
        <thead>
            <tr>
                <th>File</th>
                <th>Description</th>
                <th>Expiration Date</th>
                <th>Size</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            {foreach from=$uploaded_files item=file}
            <tr>
                <td><a href="{$file.url}" target="_blank">{$file.name}</a></td>
                <td>{$file.description}</td>
                <td>{$file.expiration_date}</td>
                <td>{$file.size}</td>
                <td>
                    <span class="status-{$file.status|escape:'htmlall':'UTF-8'}">{$file.status}</span>
                </td>
                <td>
                    <a href="delete.php?id={$file.id}" class="action-btn">Delete</a>
                </td>
            </tr>
            {/foreach}
        </tbody>
    </table>

    <div class="section-title">Missing Documents</div>
    <table class="upload-table">
        <thead>
            <tr>
                <th>Title</th>
                <th>Description</th>
                <th>Expiration Date</th>
                <th>Upload</th>
            </tr>
        </thead>
        <tbody>
            {foreach from=$missing_documents item=doc}
            <tr>
                <td>{$doc.title}</td>
                <td>{$doc.description}</td>
                <td>{$doc.expiration_date}</td>
                <td><a href="{$doc.upload_url}" class="upload-link">Upload</a></td>
            </tr>
            {/foreach}
        </tbody>
    </table>
</div>
