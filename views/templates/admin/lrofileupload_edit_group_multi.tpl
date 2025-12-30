<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Edit Upload Group</title>
    <style>
        body { font-family: sans-serif; padding: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top: 10px; }
        th, td { border: 1px solid #ccc; padding: 8px; }
        select[multiple] { height: 120px; }
        .form-group { margin-top: 15px; }
        .btn { padding: 8px 14px; background: #007bff; color: white; border: none; cursor: pointer; }
        .btn:hover { background: #0056b3; }
    </style>
</head>
<body>

<h2><?= $group['id_group'] ? 'Edit Group' : 'Create New Group' ?></h2>
<?php if (isset($_GET['success'])): ?>
    <p style="color: green;">✔ Group saved.</p>
<?php endif; ?>
<?php if ($errors): foreach ($errors as $err): ?>
    <p style="color: red;">⚠ <?= $err ?></p>
<?php endforeach; endif; ?>

<form method="post">
    <input type="hidden" name="id_group" value="<?= (int)$group['id_group'] ?>">

    <div class="form-group">
        <label>Group Name:</label>
        <input type="text" name="group_name" value="<?= htmlspecialchars($group['group_name']) ?>" required>
    </div>

    <div class="form-group">
        <label>Description:</label>
        <textarea name="description"><?= htmlspecialchars($group['description']) ?></textarea>
    </div>

    <div class="form-group">
        <label>Linked Products:</label>
        <select name="id_products[]" multiple required>
            <?php foreach ($products as $p): ?>
                <option value="<?= $p['id_product'] ?>" <?= in_array($p['id_product'], $linked_products) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($p['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <h3>Document Requirements</h3>
    <table id="doc-table">
        <thead>
            <tr>
                <th>Name</th><th>Type</th><th>Required</th><th>Description</th><th>Sort</th><th>X</th>
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
                            <option value="png" <?= $doc['file_type'] === 'png' ? 'selected' : '' ?>>PNG</option>
                        </select>
                    </td>
                    <td><input type="checkbox" name="documents[][required]" <?= $doc['required'] ? 'checked' : '' ?>></td>
                    <td><input type="text" name="documents[][description]" value="<?= htmlspecialchars($doc['description']) ?>"></td>
                    <td><input type="number" name="documents[][sort_order]" value="<?= (int)$doc['sort_order'] ?>"></td>
                    <td><button type="button" onclick="this.closest('tr').remove()">X</button></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <button type="button" onclick="addRow()" class="btn">+ Add Document</button>

    <div class="form-group">
        <button type="submit" class="btn">💾 Save</button>
    </div>
</form>

<script>
function addRow() {
    const row = `<tr>
        <td><input type="text" name="documents[][document_name]"></td>
        <td>
            <select name="documents[][file_type]">
                <option value="pdf">PDF</option>
                <option value="jpeg">JPEG</option>
                <option value="jpg">JPG</option>
                <option value="png">PNG</option>
            </select>
        </td>
        <td><input type="checkbox" name="documents[][required]"></td>
        <td><input type="text" name="documents[][description]"></td>
        <td><input type="number" name="documents[][sort_order]" value="0"></td>
        <td><button type="button" onclick="this.closest('tr').remove()">X</button></td>
    </tr>`;
    document.querySelector('#doc-table tbody').insertAdjacentHTML('beforeend', row);
}
</script>
</body>
</html>
