{* Only two buttons. No PHP, no SQL, no {PREFIX}. *}
<div class="lro-quick-actions" style="display:flex;gap:.5rem;flex-wrap:wrap">
  <a href="{$upload_url|escape:'html'}"
     class="btn btn-primary"
     style="padding:.45rem .9rem;border-radius:.5rem">
     {l s='Upload Documents' mod='lrofileupload'}
  </a>

  <a href="{$history_url|escape:'html'}"
     class="btn btn-outline-secondary"
     style="padding:.45rem .9rem;border-radius:.5rem">
     {l s='View Uploaded Files' mod='lrofileupload'}
  </a>
</div>
