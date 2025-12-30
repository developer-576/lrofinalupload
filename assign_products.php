<?php
require_once __DIR__ . '/session_bootstrap.php';

require_once(__DIR__ . '/auth.php');
require_admin_login();
require_once(__DIR__ . '/config.php');

$success = $error = '';

// Handle inline update or delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_product_name'], $_POST['product_id'])) {
        $stmt = $pdo->prepare("UPDATE psfc_product_lang SET name = ? WHERE id_product = ? AND id_lang = 1");
        $stmt->execute([trim($_POST['save_product_name']), (int)$_POST['product_id']]);
        log_admin_action("Updated product name (ID: {$_POST['product_id']})");
        $success = "Product name updated.";
    }

    if (isset($_POST['delete_product_id'])) {
        $productId = (int)$_POST['delete_product_id'];
        $stmt = $pdo->prepare("DELETE FROM psfc_lrofileupload_product_group_links WHERE id_product = ?");
        $stmt->execute([$productId]);
        log_admin_action("Unassigned product ID $productId");
        $success = "Product unassigned.";
    }

    if (isset($_POST['bulk_unassign']) && is_array($_POST['bulk_unassign'])) {
        $placeholders = implode(',', array_fill(0, count($_POST['bulk_unassign']), '?'));
        $stmt = $pdo->prepare("DELETE FROM psfc_lrofileupload_product_group_links WHERE id_product IN ($placeholders)");
        $stmt->execute($_POST['bulk_unassign']);
        log_admin_action("Bulk unassigned products: " . implode(',', $_POST['bulk_unassign']));
        $success = count($_POST['bulk_unassign']) . " products unassigned.";
    }
}

// Fetch all groups and linked products
$stmt = $pdo->query("
    SELECT g.id_group, g.group_name, g.description, p.id_product, MAX(pl.name) AS product_name
    FROM psfc_lrofileupload_product_group_links l
    JOIN psfc_lrofileupload_product_groups g ON l.id_group = g.id_group
    JOIN psfc_product p ON l.id_product = p.id_product
    LEFT JOIN psfc_product_lang pl ON p.id_product = pl.id_product AND pl.id_lang = 1
    GROUP BY g.id_group, g.group_name, g.description, p.id_product
    ORDER BY g.group_name, product_name
");

$groupedProducts = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $groupedProducts[$row['id_group']]['group_name'] = $row['group_name'];
    $groupedProducts[$row['id_group']]['description'] = $row['description'];
    $groupedProducts[$row['id_group']]['products'][] = $row;
}
?>

<?php include 'header.php'; ?>
<?php include 'nav.php'; ?>

<div class="container mt-4">
    <h2 class="mb-4">Assigned Products to File Groups</h2>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php elseif ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" onsubmit="return confirm('Are you sure you want to unassign these products?');">
        <button type="submit" class="btn btn-danger mb-3" name="bulk_unassign[]" value="">Global Bulk Unassign</button>

        <?php foreach ($groupedProducts as $groupId => $group): ?>
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center bg-primary text-white">
                    <div>
                        <strong><?= htmlspecialchars($group['group_name']) ?> [<?= (int)$groupId ?>]</strong><br>
                        <small><?= nl2br(htmlspecialchars($group['description'])) ?></small>
                    </div>
                    <button type="button" class="btn btn-light btn-sm" onclick="toggleGroup(this)">Toggle</button>
                </div>

                <div class="card-body group-table">
                    <table class="table table-bordered table-hover">
                        <thead class="table-light">
                            <tr>
                                <th><input type="checkbox" onclick="toggleAll(this)"></th>
                                <th>Product ID</th>
                                <th>Product Name</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($group['products'] as $prod): ?>
                                <tr>
                                    <td><input type="checkbox" name="bulk_unassign[]" value="<?= (int)$prod['id_product'] ?>"></td>
                                    <td><?= (int)$prod['id_product'] ?></td>
                                    <td>
                                        <form method="POST" class="d-flex gap-2">
                                            <input type="hidden" name="product_id" value="<?= (int)$prod['id_product'] ?>">
                                            <input type="text" name="save_product_name" value="<?= htmlspecialchars($prod['product_name']) ?>" class="form-control form-control-sm" required>
                                            <button class="btn btn-sm btn-outline-success" type="submit" title="Save">💾</button>
                                        </form>
                                    </td>
                                    <td>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Unassign this product?');">
                                            <input type="hidden" name="delete_product_id" value="<?= (int)$prod['id_product'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit" title="Unassign">🗑️</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <button type="submit" class="btn btn-sm btn-danger" name="bulk_unassign[]" value="">Bulk Unassign Selected (This Group)</button>
                </div>
            </div>
        <?php endforeach; ?>
    </form>
</div>

<script>
function toggleAll(master) {
    const checkboxes = master.closest('table').querySelectorAll('input[type="checkbox"]');
    checkboxes.forEach(cb => cb.checked = master.checked);
}
function toggleGroup(btn) {
    const table = btn.closest('.card').querySelector('.group-table');
    table.style.display = table.style.display === 'none' ? 'block' : 'none';
}
</script>

<?php include 'footer.php'; ?>
