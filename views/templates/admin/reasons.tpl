{* views/templates/admin/reasons.tpl *}
<div class="container mt-4">
  <h1>Rejection Reasons</h1>

  <form method="post" action="" id="reasonsForm">
    <div class="mb-3">
      <label for="new_reason" class="form-label">Add New Reason</label>
      <input type="text" class="form-control" id="new_reason" name="new_reason" placeholder="Enter new rejection reason" />
    </div>
    <button type="submit" name="add_reason" class="btn btn-primary mb-3">Add Reason</button>

    {if $reasons|@count > 0}
    <table class="table table-bordered table-striped">
      <thead>
        <tr>
          <th><input type="checkbox" id="select_all" /></th>
          <th>Reason Text</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        {foreach from=$reasons item=reason}
        <tr>
          <td><input type="checkbox" name="delete_reasons[]" value="{$reason.id_reason}" /></td>
          <td contenteditable="true" data-id="{$reason.id_reason}" class="editable-reason">{$reason.reason_text|escape}</td>
          <td>
            <button type="submit" name="delete_reason" value="{$reason.id_reason}" class="btn btn-sm btn-danger" onclick="return confirm('Delete this reason?');">Delete</button>
          </td>
        </tr>
        {/foreach}
      </tbody>
    </table>
    <button type="submit" name="delete_selected" class="btn btn-danger mt-2" onclick="return confirm('Delete selected reasons?');">Delete Selected</button>
    {/if}
  </form>
</div>

{literal}
<script>
  document.getElementById('select_all').addEventListener('change', function() {
    var checkboxes = document.querySelectorAll('input[name="delete_reasons[]"]');
    for (var checkbox of checkboxes) {
      checkbox.checked = this.checked;
    }
  });

  // Inline editing AJAX example (requires backend support)
  document.querySelectorAll('.editable-reason').forEach(function(cell) {
    cell.addEventListener('blur', function() {
      var id = this.getAttribute('data-id');
      var newText = this.textContent.trim();

      fetch('reasons.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'update_reason=1&id_reason=' + encodeURIComponent(id) + '&reason_text=' + encodeURIComponent(newText)
      }).then(response => response.text())
        .then(data => {
          // Optionally show success or error message
          console.log('Reason updated');
        });
    });
  });
</script>
{/literal}
