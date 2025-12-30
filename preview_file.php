<?php
require_once __DIR__ . '/session_bootstrap.php';

require_once(__DIR__ . '/auth.php');
require_admin_login();

$fileUrl = $_GET['file'] ?? '';
$filePath = $_SERVER['DOCUMENT_ROOT'] . $fileUrl;
$fileType = mime_content_type($filePath);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Preview File</title>
    <style>
        body { text-align: center; }
        iframe, img { width: 90%; height: 80vh; border: 1px solid #ccc; }
    </style>
</head>
<body>
    <h2>File Preview</h2>

    <?php if (strpos($fileType, 'pdf') !== false): ?>
        <iframe src="<?= htmlspecialchars($fileUrl) ?>"></iframe>
    <?php elseif (strpos($fileType, 'image') !== false): ?>
        <img src="<?= htmlspecialchars($fileUrl) ?>" alt="Image Preview">
    <?php else: ?>
        <p>Cannot preview this file type.</p>
    <?php endif; ?>

    <h3>Take Action</h3>
    <button onclick="approve()">✅ Approve</button>
    <button onclick="showReject()">❌ Reject</button>

    <div id="rejectSection" style="display:none; margin-top:10px;">
        <label>Reason:</label>
        <select id="rejectReason">
            <option value="">Select a reason</option>
            <option value="Blurry Document">Blurry Document</option>
            <option value="Incorrect Document">Incorrect Document</option>
            <option value="Unreadable Format">Unreadable Format</option>
        </select>
        <button onclick="reject()">Confirm Reject</button>
    </div>

    <script>
        const fileUrl = '<?= rawurlencode($fileUrl) ?>';

        function approve() {
            fetch('approve_file.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'file=' + fileUrl
            }).then(res => res.text()).then(alert).then(() => window.close());
        }

        function showReject() {
            document.getElementById('rejectSection').style.display = 'block';
        }

        function reject() {
            const reason = document.getElementById('rejectReason').value;
            if (!reason) return alert('Please select a reason.');

            fetch('reject_file.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'file=' + fileUrl + '&reason=' + encodeURIComponent(reason)
            }).then(res => res.text()).then(alert).then(() => window.close());
        }
    </script>
</body>
</html>
