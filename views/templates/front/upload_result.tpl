{* Upload Result Message *}
{if $success}
  <div class="alert alert-success">
    {l s='File uploaded successfully.' mod='lrofileupload'}
  </div>
{/if}

{if $errors}
  <div class="alert alert-danger">
    <ul>
      {foreach from=$errors item=error}
        <li>{$error}</li>
      {/foreach}
    </ul>
  </div>
{/if}
