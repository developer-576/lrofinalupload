{* modules/lrofileupload/views/templates/front/upload.tpl *}

<div class="container my-4">
  <h2>
    Upload Documents
    <small>({$max_mb|intval}MB max · PDF/JPG/PNG)</small>
  </h2>

  {if !$cards || count($cards) == 0}
    <div class="alert alert-info mt-3">
      There are currently no documents available for upload.
      {*
        This usually means either:
        - You have not yet purchased a product that unlocks any document groups, or
        - All your required documents for unlocked products have been approved.
      *}
    </div>
  {else}

    {foreach from=$cards item=card}
      <div class="card mb-4 shadow-sm">
        <div class="card-header bg-primary text-white">
          <strong>{$card.name|escape:'html':'UTF-8'}</strong>
        </div>

        <div class="card-body">
          {if $card.desc}
            <p class="text-muted small">{$card.desc|escape:'html':'UTF-8'}</p>
          {/if}

          {foreach from=$card.requirements item=req}
            <div class="border rounded p-3 mb-3 upload-block"
                 data-group="{$card.id_group|intval}"
                 data-req="{$req.id_requirement|intval}">

              <div class="d-flex justify-content-between align-items-start mb-1">
                <h5 class="mb-1">
                  {$req.title|escape:'html':'UTF-8'}
                  {if $req.required}
                    <span class="text-danger">*</span>
                  {/if}
                </h5>

                {if $req.status_label}
                  {if $req.last_status == 'pending'}
                    <span class="badge bg-warning text-dark">
                      {$req.status_label|escape:'html':'UTF-8'}
                    </span>
                  {elseif $req.last_status == 'rejected'}
                    <span class="badge bg-danger">
                      {$req.status_label|escape:'html':'UTF-8'}
                    </span>
                  {/if}
                {/if}
              </div>

              {if $req.last_status == 'rejected' && $req.last_reason}
                <div class="text-muted small mb-1">
                  Rejection reason:
                  {$req.last_reason|escape:'html':'UTF-8'}
                </div>
              {/if}

              {if $req.description}
                <p class="text-muted small mb-2">
                  {$req.description|escape:'html':'UTF-8'}
                </p>
              {/if}

              {* Drag & drop + click-to-browse area *}
              <div class="dropzone-area border border-secondary rounded p-4 text-center bg-light"
                   data-group="{$card.id_group|intval}"
                   data-req="{$req.id_requirement|intval}">
                <div class="fw-bold mb-1">
                  Drag & drop your file here
                </div>
                <div class="text-muted small mb-2">
                  or click to browse (PDF / JPG / PNG)
                </div>
                <input type="file"
                       class="dz-input"
                       accept=".pdf,.jpg,.jpeg,.png"
                       hidden>
              </div>

              {* Progress bar *}
              <div class="progress mt-3 d-none upload-progress">
                <div class="progress-bar progress-bar-striped progress-bar-animated"
                     role="progressbar"
                     style="width: 0%">
                  0%
                </div>
              </div>

              <div class="text-muted small mt-2">
                Max {$max_mb|intval}MB
              </div>

            </div>
          {/foreach}
        </div>
      </div>
    {/foreach}

  {/if}
</div>

{* ============================================================
   JavaScript: drag & drop + auto upload + progress
   ============================================================ *}
<script>
(function() {
  var uploadURL = '{$upload_url|escape:'javascript'}';

  function uploadFile(file, group, req, block) {
    if (!file) { return; }

    var maxMB = {$max_mb|intval};
    if (file.size <= 0 || file.size > maxMB * 1024 * 1024) {
      alert('Invalid file size. Maximum ' + maxMB + 'MB.');
      return;
    }

    var ext = file.name.split('.').pop().toLowerCase();
    var allowed = ['pdf','jpg','jpeg','png'];
    if (allowed.indexOf(ext) === -1) {
      alert('Only PDF, JPG, and PNG files are allowed.');
      return;
    }

    var progressWrap = block.querySelector('.upload-progress');
    var bar = progressWrap.querySelector('.progress-bar');
    progressWrap.classList.remove('d-none');
    bar.classList.remove('bg-success','bg-danger');
    bar.style.width = '0%';
    bar.textContent = '0%';

    var formData = new FormData();
    formData.append('file', file);
    formData.append('id_group', group);
    formData.append('id_requirement', req);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', uploadURL, true);

    xhr.upload.addEventListener('progress', function(e) {
      if (e.lengthComputable) {
        var pct = Math.round((e.loaded / e.total) * 100);
        bar.style.width = pct + '%';
        bar.textContent = pct + '%';
      }
    });

    xhr.onreadystatechange = function() {
      if (xhr.readyState === 4) {
        if (xhr.status === 200) {
          try {
            var res = JSON.parse(xhr.responseText);
            if (res.success) {
              bar.classList.add('bg-success');
              bar.textContent = 'Uploaded';
              // Refresh page so status + visibility update
              setTimeout(function() {
                window.location.reload();
              }, 800);
            } else {
              bar.classList.add('bg-danger');
              bar.textContent = res.message || 'Upload failed';
            }
          } catch (e) {
            bar.classList.add('bg-danger');
            bar.textContent = 'Upload failed';
          }
        } else {
          bar.classList.add('bg-danger');
          bar.textContent = 'Server error';
        }
      }
    };

    xhr.send(formData);
  }

  document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.dropzone-area').forEach(function(zone) {
      var block = zone.closest('.upload-block');
      var input = zone.querySelector('.dz-input');

      // Click to choose file
      zone.addEventListener('click', function() {
        input.click();
      });

      input.addEventListener('change', function(e) {
        if (e.target.files && e.target.files.length > 0) {
          var file = e.target.files[0];
          uploadFile(
            file,
            zone.getAttribute('data-group'),
            zone.getAttribute('data-req'),
            block
          );
        }
      });

      // Drag & drop
      zone.addEventListener('dragover', function(e) {
        e.preventDefault();
        zone.classList.add('bg-white');
      });

      zone.addEventListener('dragleave', function(e) {
        e.preventDefault();
        zone.classList.remove('bg-white');
      });

      zone.addEventListener('drop', function(e) {
        e.preventDefault();
        zone.classList.remove('bg-white');

        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
          var file = e.dataTransfer.files[0];
          uploadFile(
            file,
            zone.getAttribute('data-group'),
            zone.getAttribute('data-req'),
            block
          );
        }
      });
    });
  });
})();
</script>
