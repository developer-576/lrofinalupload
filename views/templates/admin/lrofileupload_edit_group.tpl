<!DOCTYPE html>
<html>
<head>
    <title>Edit Upload Group</title>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; padding: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top: 10px; }
        td, th { border: 1px solid #ccc; padding: 8px; }
        input[type="text"], select, textarea { width: 100%; }
        .actions { margin-top: 15px; }
    </style>
</head>
<body>

<h2><?= $group['id_group'] ? "Edit Group" : "Create New Group" ?></h2>

<?php if (isset($_GET['success'])): ?>
    <p style="color: green;">✔ Saved successfully.</p>
<?php endif; ?>

<form method="post">
    <input type="hidden" name="id_group" value="<?= (int)$group['id_group'] ?>">

    <label>Product:</label>
    <select name="id_product" required>
        <option value="">-- Choose a product --</option>
        <?php foreach ($products as $product): ?>
            <option value="<?= $product['id_product'] ?>" <?= $product['id_product'] == $group['id_product'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($product['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label>Group Name:</label>
    <input type="text" name="group_name" value="<?= htmlspecialchars($group['group_name']) ?>" required>

    <label>Description:</label>
    <textarea name="description"><?= htmlspecialchars($group['description']) ?></textarea>

    <h3>Document Requirements</h3>
    <table id="doc-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Type</th>
                <th>Required</th>
                <th>Description</th>
                <th>Order</th>
                <th>Remove</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($documents as $doc): ?>
            <tr>
                <td><input type="text" name="documents[][document_name]" value="<?= htmlspecialchars($doc['document_name']) ?>"></td>
                <td>
                    <select name="documents[][file_type]">
                        <option value="pdf" <?= $doc['file_type'] === 'pdf' ? 'selected' : '' ?>>PDF</option>
                        <option value="jpeg" <?= $doc['file_type'] === 'jpeg' ? 'selected' : '' ?>>JPEG</option>
                        <option value="jpg" <?= $doc['file_type'] === 'jpg' ? 'selected' : '' ?>>JPG</option>
                    </select>
                </td>
                <td><input type="checkbox" name="documents[][required]" <?= $doc['required'] ? 'checked' : '' ?>></td>
                <td><input type="text" name="documents[][description]" value="<?= htmlspecialchars($doc['description']) ?>"></td>
                <td><input type="text" name="documents[][sort_order]" value="<?= (int)$doc['sort_order'] ?>"></td>
                <td><button type="button" onclick="this.closest('tr').remove()">✖</button></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <button type="button" onclick="addRow()">+ Add Document</button>

    <div class="actions">
        <button type="submit">💾 Save</button>
    </div>
</form>

<script>
    function addRow() {
        const row = `
            <tr>
                <td><input type="text" name="documents[][document_name]"></td>
                <td>
                    <select name="documents[][file_type]">
                        <option value="pdf">PDF</option>
                        <option value="jpeg">JPEG</option>
                        <option value="jpg">JPG</option>
                    </select>
                </td>
                <td><input type="checkbox" name="documents[][required]"></td>
                <td><input type="text" name="documents[][description]"></td>
                <td><input type="text" name="documents[][sort_order]" value="0"></td>
                <td><button type="button" onclick="this.closest('tr').remove()">✖</button></td>
            </tr>`;
        document.querySelector('#doc-table tbody').insertAdjacentHTML('beforeend', row);
    }
</script>

</body>
</html>
