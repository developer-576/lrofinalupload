<?php
/**************************************************
 * modules/lrofileupload/admin/pdf_viewer.php
 * Minimal PDF.js-based viewer with NO download/print UI.
 * Expects ?u=<absolute or same-origin URL to the PDF resource>
 * We purposely do not expose any download/print controls.
 **************************************************/
declare(strict_types=1);

error_reporting(0);
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$u = isset($_GET['u']) ? (string)$_GET['u'] : '';
// Very basic allowlist: only same-origin or absolute https to this host
$ok = false;
if ($u !== '') {
  // If relative, make it absolute
  if (strpos($u, '//') === false) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base   = $scheme.'://'.$host;
    if ($u[0] !== '/') $u = '/'.$u;
    $u = $base.$u;
  }
  // now $u is absolute; ensure host matches current host
  $hostNow = $_SERVER['HTTP_HOST'] ?? '';
  $ok = (parse_url($u, PHP_URL_HOST) === $hostNow);
}
if (!$ok) {
  http_response_code(400);
  echo 'Bad request';
  exit;
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Preview</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    :root { color-scheme: light; }
    html,body{height:100%;margin:0;background:#f9fafb;font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial;}
    .bar{display:flex;align-items:center;gap:.5rem;padding:.5rem .75rem;border-bottom:1px solid #e5e7eb;background:#fafafa;position:sticky;top:0;z-index:5}
    .bar .sp{flex:1}
    .bar button{border:1px solid #d0d7de;background:#fff;border-radius:.375rem;padding:.25rem .5rem;cursor:pointer}
    .wrap{height:calc(100% - 42px);overflow:auto}
    .page{display:flex;justify-content:center;margin:12px 0}
    canvas{box-shadow:0 1px 2px rgba(0,0,0,.07),0 0 0 1px rgba(0,0,0,.04);background:#fff}
  </style>

  <!-- PDF.js (no toolbar) -->
  <script type="importmap">
  {
    "imports": {
      "pdfjs-dist": "https://cdn.jsdelivr.net/npm/pdfjs-dist@4.6.82/+esm"
    }
  }
  </script>
  <script type="module">
    import * as pdfjsDist from "pdfjs-dist";

    // Worker (required for performance)
    pdfjsDist.GlobalWorkerOptions.workerSrc =
      "https://cdn.jsdelivr.net/npm/pdfjs-dist@4.6.82/build/pdf.worker.min.mjs";

    const PDF_URL = <?= json_encode($u, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;

    const wrap   = document.getElementById('wrap');
    const btnPrev= document.getElementById('prev');
    const btnNext= document.getElementById('next');
    const btnZoIn= document.getElementById('zoomin');
    const btnZoOu= document.getElementById('zoomout');
    const pageLab= document.getElementById('pagelabel');

    let pdfDoc = null;
    let scale = 1.1;
    let currPage = 1;
    let rendering = false;

    function clearPages(){
      wrap.innerHTML = '';
    }

    async function renderPage(num){
      if (!pdfDoc) return;
      rendering = true;
      clearPages();

      // Render only one page at a time (fast, minimal)
      const page = await pdfDoc.getPage(num);
      const viewport = page.getViewport({ scale });
      const canvas = document.createElement('canvas');
      const ctx = canvas.getContext('2d');
      canvas.width  = viewport.width;
      canvas.height = viewport.height;
      const holder = document.createElement('div');
      holder.className = 'page';
      holder.appendChild(canvas);
      wrap.appendChild(holder);
      await page.render({ canvasContext: ctx, viewport }).promise;

      pageLab.textContent = num + ' / ' + pdfDoc.numPages;
      rendering = false;
    }

    async function loadDoc(){
      try{
        const loadingTask = pdfjsDist.getDocument({ url: PDF_URL, withCredentials: true });
        pdfDoc = await loadingTask.promise;
        currPage = 1;
        await renderPage(currPage);
      }catch(e){
        clearPages();
        const err = document.createElement('div');
        err.style.padding = '1rem';
        err.textContent = 'Failed to load PDF.';
        wrap.appendChild(err);
      }
    }

    btnPrev.addEventListener('click', async ()=>{
      if (!pdfDoc || rendering) return;
      if (currPage <= 1) return;
      currPage--;
      await renderPage(currPage);
    });
    btnNext.addEventListener('click', async ()=>{
      if (!pdfDoc || rendering) return;
      if (currPage >= pdfDoc.numPages) return;
      currPage++;
      await renderPage(currPage);
    });
    btnZoIn.addEventListener('click', async ()=>{
      if (!pdfDoc || rendering) return;
      scale = Math.min(scale + 0.1, 3);
      await renderPage(currPage);
    });
    btnZoOu.addEventListener('click', async ()=>{
      if (!pdfDoc || rendering) return;
      scale = Math.max(scale - 0.1, 0.4);
      await renderPage(currPage);
    });

    // Block printing via keyboard (Ctrl/Cmd+P) inside this iframe
    window.addEventListener('keydown', (e)=>{
      if ((e.ctrlKey || e.metaKey) && (e.key === 'p' || e.key === 'P')) {
        e.preventDefault();
        e.stopImmediatePropagation();
      }
    });

    await loadDoc();
  </script>
</head>
<body>
  <div class="bar">
    <button id="prev" title="Previous page">Prev</button>
    <button id="next" title="Next page">Next</button>
    <span id="pagelabel" class="sp">—</span>
    <button id="zoomout" title="Zoom out">−</button>
    <button id="zoomin"  title="Zoom in">+</button>
    <!-- No Download / No Print / No Open-in-new-tab here -->
  </div>
  <div id="wrap" class="wrap" aria-label="PDF pages"></div>
</body>
</html>
