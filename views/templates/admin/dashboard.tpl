{extends file="file:./../layouts/admin_layout.tpl"}

{block name='page_content'}
<h2>Document Management Dashboard</h2>

<div style="margin-bottom: 20px;">
    <form method="get" action="dashboard.php">
        <label for="filter_status">Filter by status:</label>
        <select name="status" id="filter_status" onchange="this.form.submit()">
            <option value="">-- All --</option>
            <option value="pending" {if $selected_status == 'pending'}selected{/if}>Pending</option>
            <option value="approved" {if $selected_status == 'approved'}selected{/if}>Approved</option>
            <option value="rejected" {if $selected_status == 'rejected'}selected{/if}>Rejected</option>
        </select>
    </form>
</div>

<table class="table">
    <thead>
        <tr>
            <th>Customer</th>
            <th>Document</th>
            <th>Status</th>
            <th>Uploaded At</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        {foreach $documents as $doc}
        <tr>
            <td>{$doc.customer_name} ({$doc.customer_email})</td>
            <td><a href="download.php?id={$doc.id}">{$doc.original_name}</a></td>
            <td>{$doc.status|capitalize}</td>
            <td>{$doc.uploaded_at}</td>
            <td>
                {if $doc.status == 'pending'}
                    <a href="approve.php?id={$doc.id}" class="btn btn-success btn-sm">Approve</a>
                    <a href="reject.php?id={$doc.id}" class="btn btn-danger btn-sm">Reject</a>
                {/if}
                <a href="delete.php?id={$doc.id}" class="btn btn-outline-danger btn-sm" onclick="return confirm('Are you sure?')">Delete</a>
            </td>
        </tr>
        {/foreach}
    </tbody>
</table>
{/block}
