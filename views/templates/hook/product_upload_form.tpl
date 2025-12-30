<div class="product-upload-form" style="margin-top: 20px;">
    <form action="{$upload_action}" method="post" enctype="multipart/form-data">
        <input type="hidden" name="id_product" value="{$product_id}" />
        <div class="form-group">
            <label for="upload_file">Upload a file for this product:</label>
            <input type="file" name="upload_file" id="upload_file" required />
        </div>
        <button type="submit" class="btn btn-primary">Submit File</button>
    </form>
</div>
