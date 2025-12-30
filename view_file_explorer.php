<?php
require_once __DIR__ . '/session_bootstrap.php';

require_once(__DIR__ . '/auth.php');
require_admin_login();

echo "<h2>📂 File Explorer: Customer Uploads</h2>";

$baseDir = dirname(__FILE__) . '/../../../uploads_lrofileupload/';
$baseUrl = '/uploads_lrofileupload/';

function listFiles($dir, $url, $level = 0) {
    if (!is_dir($dir)) return;

    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;

        $path = $dir . '/' . $item;
        $pathUrl = $url . rawurlencode($item);
        $indent = str_repeat('&nbsp;&nbsp;&nbsp;', $level);

        if (is_dir($path)) {
            echo "{$indent}📁 <strong>$item</strong><br>";
            listFiles($path, $pathUrl . '/', $level + 1);
        } else {
            echo "{$indent}📄 $item 
            <button onclick=\"previewFile('$pathUrl')\">Preview</button><br>";
        }
    }
}

if (is_dir($baseDir)) {
    listFiles($baseDir, $baseUrl);
} else {
    echo "<p>No uploads directory found.</p>";
}

echo "<br><a href='dashboard.php'>⬅ Back to Dashboard</a>";
?>

<script>
function previewFile(fileUrl) {
    window.open('preview_file.php?file=' + encodeURIComponent(fileUrl), '_blank', 'width=1200,height=800');
}
</script>
