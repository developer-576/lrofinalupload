<div class="panel">
    <h3><i class="icon icon-upload"></i> {l s='File Upload Configuration' mod='lrofileupload'}</h3>
    
    <div class="panel-footer">
        <button type="button" class="btn btn-default" id="add-product-group">
            <i class="icon icon-plus"></i> {l s='Add Product Group' mod='lrofileupload'}
        </button>
    </div>

    <div id="product-groups">
        {foreach from=$file_groups key=product_id item=group}
            <div class="panel product-group" data-product-id="{$product_id}">
                <div class="panel-heading">
                    <h3 class="panel-title">
                        {if isset($product_info[$product_id])}
                            {$product_info[$product_id].name} 
                            <small>(ID: {$product_id} - Ref: {$product_info[$product_id].reference})</small>
                        {else}
                            {l s='Product ID' mod='lrofileupload'}: {$product_id}
                        {/if}
                        <button type="button" class="btn btn-danger btn-sm pull-right delete-group">
                            <i class="icon icon-trash"></i>
                        </button>
                    </h3>
                </div>
                <div class="panel-body">
                    <div class="form-group">
                        <label>{l s='Group Name' mod='lrofileupload'}</label>
                        <input type="text" class="form-control group-name" name="group_name" value="{if isset($group.group_name)}{$group.group_name}{/if}" placeholder="{l s='Enter group name' mod='lrofileupload'}">
                    </div>
                    <div class="documents">
                        {foreach from=$group.documents item=doc}
                            <div class="document-item">
                                <div class="row">
                                    <div class="col-md-5">
                                        <div class="form-group">
                                            <label>{l s='Document Title' mod='lrofileupload'}</label>
                                            <input type="text" class="form-control" name="title" value="{$doc.title}">
                                        </div>
                                    </div>
                                    <div class="col-md-5">
                                        <div class="form-group">
                                            <label>{l s='File Type' mod='lrofileupload'}</label>
                                            <select class="form-control" name="file_type">
                                                <option value="pdf" {if $doc.file_type == 'pdf'}selected{/if}>PDF</option>
                                                <option value="jpeg" {if $doc.file_type == 'jpeg'}selected{/if}>JPEG</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="form-group">
                                            <label>&nbsp;</label>
                                            <button type="button" class="btn btn-danger btn-block delete-document">
                                                <i class="icon icon-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        {/foreach}
                    </div>
                    <button type="button" class="btn btn-default add-document">
                        <i class="icon icon-plus"></i> {l s='Add Document' mod='lrofileupload'}
                    </button>
                </div>
            </div>
        {/foreach}
    </div>

    <div class="panel-footer">
        <button type="button" class="btn btn-primary" id="save-configuration">
            <i class="icon icon-save"></i> {l s='Save Configuration' mod='lrofileupload'}
        </button>
    </div>
</div>

<script type="text/javascript">
{literal}
    $(document).ready(function() {
        // Add new product group
        $('#add-product-group').click(function() {
            var productId = prompt('Enter Product ID:');
            if (productId) {
                var template = 
                    '<div class="panel product-group" data-product-id="' + productId + '">' +
                    '<div class="panel-heading">' +
                    '<h3 class="panel-title">' +
                    'Product ID: ' + productId +
                    '<button type="button" class="btn btn-danger btn-sm pull-right delete-group">' +
                    '<i class="icon icon-trash"></i>' +
                    '</button>' +
                    '</h3>' +
                    '</div>' +
                    '<div class="panel-body">' +
                    '<div class="form-group">' +
                    '<label>Group Name</label>' +
                    '<input type="text" class="form-control group-name" name="group_name" placeholder="Enter group name">' +
                    '</div>' +
                    '<div class="documents"></div>' +
                    '<button type="button" class="btn btn-default add-document">' +
                    '<i class="icon icon-plus"></i> Add Document' +
                    '</button>' +
                    '</div>' +
                    '</div>';
                $('#product-groups').append(template);
            }
        });

        // Add new document
        $(document).on('click', '.add-document', function() {
            var template = 
                '<div class="document-item">' +
                '<div class="form-group">' +
                '<label>Document Title</label>' +
                '<input type="text" class="form-control" name="title">' +
                '</div>' +
                '<div class="form-group">' +
                '<label>File Type</label>' +
                '<select class="form-control" name="file_type">' +
                '<option value="pdf">PDF</option>' +
                '<option value="jpeg">JPEG</option>' +
                '</select>' +
                '</div>' +
                '<button type="button" class="btn btn-danger btn-sm delete-document">' +
                '<i class="icon icon-trash"></i> Delete' +
                '</button>' +
                '</div>';
            $(this).siblings('.documents').append(template);
        });

        // Delete document
        $(document).on('click', '.delete-document', function() {
            $(this).closest('.document-item').remove();
        });

        // Delete group
        $(document).on('click', '.delete-group', function() {
            $(this).closest('.product-group').remove();
        });

        // Save configuration
        $('#save-configuration').click(function() {
            var configuration = {};
            $('.product-group').each(function() {
                var productId = $(this).data('product-id');
                var groupName = $(this).find('.group-name').val();
                var documents = [];
                $(this).find('.document-item').each(function() {
                    documents.push({
                        title: $(this).find('[name="title"]').val(),
                        file_type: $(this).find('[name="file_type"]').val()
                    });
                });
                configuration[productId] = {
                    group_name: groupName,
                    documents: documents
                };
            });

            $.ajax({
                url: '{/literal}{$link->getAdminLink("AdminModules")}&configure=lrofileupload&ajax=1&action=saveConfiguration{literal}',
                type: 'POST',
                data: {
                    configuration: JSON.stringify(configuration)
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showSuccessMessage('Configuration saved successfully');
                    } else {
                        showErrorMessage('Error saving configuration: ' + (response.error || 'Unknown error'));
                    }
                },
                error: function(xhr, status, error) {
                    showErrorMessage('Error saving configuration: ' + error);
                    console.error('AJAX Error:', xhr.responseText);
                }
            });
        });
    });
{/literal}
</script>

<style>
.product-group {
    margin-bottom: 20px;
}
.document-item {
    border: 1px solid #ddd;
    padding: 15px;
    margin-bottom: 10px;
    border-radius: 4px;
}
.document-item .form-group {
    margin-bottom: 10px;
}
</style> 