{* views/templates/admin/email_settings.tpl *}
<div class="container mt-4">
  <h1>Email Settings</h1>

  {if isset($success)}
    <div class="alert alert-success">{$success|escape}</div>
  {/if}

  <form method="post" action="">
    <div class="mb-3">
      <label for="email_sender" class="form-label">Sender Name</label>
      <input type="text" class="form-control" id="email_sender" name="email_sender" value="{$email_sender|escape}" required />
    </div>
    <div class="mb-3">
      <label for="email_reply_to" class="form-label">Reply-To Email</label>
      <input type="email" class="form-control" id="email_reply_to" name="email_reply_to" value="{$email_reply_to|escape}" required />
    </div>
    <button type="submit" class="btn btn-primary">Save Settings</button>
  </form>
</div>
