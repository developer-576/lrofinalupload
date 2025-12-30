<?php
/**
 * modules/lrofileupload/admin/serve_file_safe.php
 * Safe file server for LRO File Uploads (admin side).
 *
 * - Auth guard matches your admin login.
 * - Accepts ?file=customer_{id}/group_{id}/[ref_xxx/]<filename>
 * - Tries multiple storage roots and chooses the one that actually contains the file.
 * - Inline or download via &download=1
 * - Debug view via &debug=1
 */
declare(strict_types=1);

// ----------------------------------------------------------------------------
// Show PHP errors while you debug (you can disable later if you prefer)
// ----------------------------------------------------------------------------
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// ----------------------------------------------------------------------------
// Auth (align with your admin guard)
// ----------------------------------------------------------------------------
require_once __DIR__ . '/_bootstrap.php';
if (function_exists('lro_require_admin')) {
    lro_require_admin(false);
} elseif (function_exists('require_admin_login')) {
    require_admin_login(false);
} else {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['admin_id'])) { http_response_code(403); exit('Forbidden'); }
}

// ============================================================================
// Helpers
// ============================================================================

/**
 * Return existing, readable candidate storage roots (ordered).
 * We include both the outside-webroot location and the common public_html path
 * because different installs may place files in either.
 */
function storage_candidates(): array {
    $doc    = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $parent = $doc ? rtrim(dirname($doc), '/') : '';

    $cands = array_values(array_filter([
        // Preferred: outside webroot (your server really uses this)
        $parent ? ($parent . '/uploads_lrofileupload') : null,

        // Public path frequently used by Prestashop setups
        $doc ? ($doc . '/upload/lrofileupload') : null,

        // Additional Prestashop-aware fallbacks
        defined('_PS_ROOT_DIR_')   ? (_PS_ROOT_DIR_   . '/upload/lrofileupload')          : null,
        defined('_PS_MODULE_DIR_') ? (_PS_MODULE_DIR_ . 'lrofileupload/storage')          : null,
    ]));

    $out = [];
    foreach ($cands as $p) {
        if (is_dir($p) && is_readable($p)) $out[] = rtrim($p, '/');
    }
    return $out;
}

/**
 * Given a sanitized relative path, pick the storage base that actually
 * contains that file. If none match, fall back to the first readable base.
 */
function storage_base_for(string $rel): ?string {
    foreach (storage_candidates() as $base) {
        $try = $base . '/' . $rel;
        if (is_file($try)) return $base; // found the file here
    }
    $cands = storage_candidates();
    return $cands[0] ?? null;
}

/**
 * Normalize & validate the ?file=... parameter.
 * Expected shape: customer_{id}/group_{id}/[ref_xxx/]<filename>
 */
function safeRel($raw): ?string {
    if (!is_string($raw) || $raw === '') return null;

    // Normalize encoding (handle double-encoded urls)
    $s = $raw;
    for ($i = 0; $i < 3; $i++) {
        $prev = $s;
        $s = rawurldecode($s);
        if ($s === $prev) break;
    }

    // Standardize slashes and trim
    $s = str_replace('\\', '/', $s);
    $s = trim($s);
    $s = preg_replace('#^/+|/+$#', '', $s);

    // No null bytes or traversal
    if (strpos($s, "\0") !== false) return null;
    if (preg_match('#(?:\.\./|\./|^\.{1,2}$|//)#', $s)) return null;

    // Validate structure
    $parts = explode('/', $s);
    if (count($parts) < 3 || count($parts) > 4) return null;
    if (!preg_match('#^customer_[0-9]+$#', $parts[0])) return null;
    if (!preg_match('#^group_[0-9]+$#',   $parts[1])) return null;

    // Optional ref_... segment
    $idx = 2;
    if (preg_match('#^ref_[^/]+$#', $parts[2])) $idx = 3;

    if (!isset($parts[$idx])) return null; // must have a filename
    $fname = $parts[$idx];

    // No path separators in filename
    if ($fname === '' || preg_match('#[\/]#', $fname)) return null;

    return implode('/', $parts);
}

/** Plain-text error + exit. */
function text_exit(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

/** Lightweight MIME detection. */
function detect_mime(string $file): string {
    if (function_exists('mime_content_type')) {
        $t = @mime_content_type($file);
        if ($t) return $t;
    }
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $map = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'txt'  => 'text/plain',
        'csv'  => 'text/csv',
        'zip'  => 'application/zip',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

// ============================================================================
// Main
// ============================================================================

$rel      = safeRel($_GET['file'] ?? null);
$download = isset($_GET['download']) && $_GET['download'] !== '0';
$debug    = isset($_GET['debug']);

$base = $rel ? storage_base_for($rel) : null;
$abs  = ($base && $rel) ? ($base . '/' . $rel) : null;

$absReal  = ($abs && file_exists($abs)) ? realpath($abs)  : false;
$baseReal = ($base && file_exists($base)) ? realpath($base) : false;

// Debug page (no file streamed)
if ($debug) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "DEBUG: serve_file_safe.php\n";
    echo "document_root: " . ($_SERVER['DOCUMENT_ROOT'] ?? '(null)') . "\n";
    echo "candidates:\n";
    foreach (storage_candidates() as $i => $p) {
        echo "  [$i] $p\n";
    }
    echo "rel:        " . var_export($rel, true) . "\n";
    echo "base:       " . ($base ?: '(null)') . "\n";
    echo "abs:        " . ($abs  ?: '(null)') . "\n";
    echo "abs_real:   " . ($absReal  ?: '(false)') . "\n";
    echo "base_real:  " . ($baseReal ?: '(false)') . "\n";
    echo "exists:     " . (($absReal && is_file($absReal)) ? 'yes' : 'no') . "\n";
    echo "download:   " . ($download ? '1' : '0') . "\n";
    exit;
}

// Validate request
if (!$rel)             text_exit(400, 'Bad request (invalid file parameter).');
if (!$base)            text_exit(404, 'Not found');
if (!$absReal)         text_exit(404, 'Not found');
if (!$baseReal)        text_exit(500, 'Storage base not resolvable');
if (strpos($absReal, $baseReal) !== 0) text_exit(403, 'Forbidden');
if (!is_file($absReal)) text_exit(404, 'Not found');

// Stream the file
$mime = detect_mime($absReal);
$size = filesize($absReal);
$fn   = basename($absReal);

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)$size);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-store, no-cache, must-revalidate');

$disp = $download ? 'attachment' : 'inline';
$encoded = rawurlencode($fn);
header("Content-Disposition: {$disp}; filename=\"{$encoded}\"; filename*=UTF-8''{$encoded}");

// Chunked read to avoid memory spikes
$fp = @fopen($absReal, 'rb');
if (!$fp) text_exit(500, 'Could not open file');
while (!feof($fp)) {
    $buf = fread($fp, 8192);
    if ($buf === false) break;
    echo $buf;
    flush();
}
fclose($fp);
