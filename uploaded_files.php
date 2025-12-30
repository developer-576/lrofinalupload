<?php
require_once __DIR__ . '/session_bootstrap.php';

require_once(__DIR__ . '/auth.php');
require_admin_login(true);
require_once(__DIR__ . '/config.php');

$prefix = _DB_PREFIX_;
$current_admin = $_SESSION['admin_name'] ?? 'Unknown';

// Fetch uploaded files
$stmt = $pdo->query("SELECT f.*, c.firstname, c.lastname, p.name AS product_name
    FROM {$prefix}lrofileupload_files f
    LEFT JOIN {$prefix}customer c ON f.id_customer = c.id_customer
    LEFT JOIN {$prefix}product_lang p ON f.id_product = p.id_product AND p.id_lang = 1
    ORDER BY f.date_uploaded DESC");
$files = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>All Uploaded Files</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <style>
        body { padding: 20px; background: #f8f9fa; }
        table th, table td { vertical-align: middle; }
    </style>
</head>
<body>
<?php include(__DIR__ . '/nav.php'); ?>
<div class="container">
    <h2>Uploaded Files</h2>
    <p class="text-muted">Viewing as <strong><?= htmlspecialchars($current_admin) ?></strong></p>

    <table class="table table-bordered table-striped mt-4">
        <thead class="table-light">
            <tr>
                <th>File</th>
                <th>Customer</th>
                <th>Product</th>
                <th>Status</th>
                <th>Uploaded</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($files as $file): ?>
                <tr>
                    <td><?= htmlspecialchars($file['file_name']) ?></td>
                    <td><?= htmlspecialchars($file['firstname'] . ' ' . $file['lastname']) ?></td>
                    <td><?= htmlspecialchars($file['product_name']) ?></td>
                    <td>
                        <?php if ($file['status'] == 'approved'): ?>
                            <span class="badge bg-success">Approved</span>
                        <?php elseif ($file['status'] == 'rejected'): ?>
                            <span class="badge bg-danger">Rejected</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Pending</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($file['date_uploaded']) ?></td>
                    <td>
                        <a href="../uploads/<?= urlencode($file['file_path']) ?>" target="_blank" class="btn btn-sm btn-primary">View</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (count($files) === 0): ?>
                <tr><td colspan="6" class="text-center text-muted">No uploaded files found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
</body>
</html>
