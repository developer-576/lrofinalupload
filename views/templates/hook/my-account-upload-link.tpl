<div class="card" style="border-radius:.5rem">
  <div class="card-body">
    <h5 class="card-title" style="margin-bottom:.5rem">{l s='Upload Documents' mod='lrofileupload'}</h5>
    <p class="card-text" style="margin-bottom:.75rem">
      {l s='Send your required documents securely.' mod='lrofileupload'}
    </p>
    <div class="d-flex gap-2">
      <a class="btn btn-primary" href="{$upload_url|escape:'html'}">{l s='Go To Uploader' mod='lrofileupload'}</a>
      <a class="btn btn-outline-secondary" href="{$history_url|escape:'html'}">{l s='View Uploaded Files' mod='lrofileupload'}</a>
    </div>
  </div>
</div>
