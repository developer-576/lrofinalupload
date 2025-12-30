<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Admin File Review</title>
    <style>
        body { font-family: Arial; padding: 20px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { padding: 8px; border: 1px solid #ccc; }
        .badge { padding: 4px 8px; border-radius: 4px; color: white; font-size: 0.9em; }
        .badge.pending { background: orange; }
        .badge.approved { background: green; }
        .badge.rejected { background: red; }
        .actions button { margin-right: 4px; }
    </style>
</head>
<body>

<h2>Uploaded Files</h2>

<form method="get">
    <label for="status">Filter by Status:</label>
    <select name="status" id="status" onchange="this.form.submit()">
        <option value="all" {if $status_filter == 'all'}selected{/if}>All</option>
        <option value="pending" {if $status_filter == 'pending'}selected{/if}>Pending</option>
        <option value="approved" {if $status_filter == 'approved'}selected{/if}>Approved</option>
        <option value="rejected" {if $status_filter == 'rejected'}selected{/if}>Rejected</option>
    </select>
</form>

<table>
    <thead>
        <tr>
            <th>Customer</th>
            <th>Group</th>
            <th>Document</th>
            <th>File</th>
            <th>Status</th>
            <th>Date</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    {foreach from=$files item=file}
        <tr>
            <td>{$file.firstname} {$file.lastname}<br><small>{$file.email}</small></td>
            <td>{$file.group_name}</td>
            <td>{$file.document_name}</td>
            <td>
                <a href="{$file.file_path}" target="_blank">📄 {$file.file_name}</a>
            </td>
            <td><span class="badge {$file.status}">{$file.status|capitalize}</span></td>
            <td>{$file.date_uploaded}</td>
            <td class="actions">
                {if $file.status != 'approved'}
                    <form method="post" action="approve.php" style="display:inline;">
                        <input type="hidden" name="id_file" value="{$file.id_lrofileupload_file}">
                        <button type="submit">✅ Approve</button>
                    </form>
                {/if}
                {if $file.status != 'rejected'}
                    <form method="post" action="reject.php" style="display:inline;">
                        <input type="hidden" name="id_file" value="{$file.id_lrofileupload_file}">
                        <select name="reason_id" required>
                            <option value="">Reject…</option>
                            {foreach from=$reasons item=reason}
                                <option value="{$reason.id_reason}">{$reason.reason_text|truncate:30}</option>
                            {/foreach}
                        </select>
                        <button type="submit">❌</button>
                    </form>
                {/if}
                {if $is_master}
                    <form method="post" action="delete.php" style="display:inline;">
                        <input type="hidden" name="id_file" value="{$file.id_lrofileupload_file}">
                        <button type="submit" onclick="return confirm('Are you sure?')">🗑 Delete</button>
                    </form>
                {/if}
            </td>
        </tr>
    {/foreach}
    </tbody>
</table>

</body>
</html>
