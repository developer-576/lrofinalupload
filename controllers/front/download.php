<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class LrofileuploadDownloadModuleFrontController extends ModuleFrontController
{
    public $ssl            = true;
    public $ajax           = true;
    public $display_header = false;
    public $display_footer = false;

    public function initContent()
    {
        parent::initContent();
        $this->serve();
        exit;
    }

    private function serve(): void
    {
        // Require login
        $cust = $this->context->customer;
        if (!$cust || !$cust->id) {
            $this->deny(403);
        }

        $idCustomer = (int) $cust->id;
        $idUpload   = (int) Tools::getValue('id_upload', 0);
        $fileParam  = (string) Tools::getValue('file', '');
        $groupParam = (int) Tools::getValue('g', 0);

        $db  = Db::getInstance();
        $tbl = _DB_PREFIX_ . 'lrofileupload_uploads';

        // ---- Find row safely using DbQuery (no raw SQL literals with strings) ----
        if ($idUpload > 0) {
            $q = (new DbQuery())
                ->select('id_upload,id_customer,id_requirement,id_group,file_name,original_name,status,uploaded_at,approved_at,rejected_at,rejection_reason,order_ref,recording_number')
                ->from('lrofileupload_uploads')
                ->where('id_upload=' . (int) $idUpload)
                ->where('id_customer=' . (int) $idCustomer)
                ->limit(1);

            $row = $db->getRow($q);
        } else {
            $fileParam = trim($fileParam);
            if ($fileParam === '') {
                $this->deny(400);
            }

            $q = (new DbQuery())
                ->select('id_upload,id_customer,id_requirement,id_group,file_name,original_name,status,uploaded_at,approved_at,rejected_at,rejection_reason,order_ref,recording_number')
                ->from('lrofileupload_uploads')
                ->where('id_customer=' . (int) $idCustomer)
                ->where(
                    "(file_name='" . pSQL($fileParam) .
                    "' OR original_name='" . pSQL($fileParam) . "')"
                );

            if ($groupParam > 0) {
                $q->where('id_group=' . (int) $groupParam);
            }

            $q->orderBy('uploaded_at DESC, id_upload DESC')->limit(1);
            $row = $db->getRow($q);
        }

        if (!$row) {
            $this->deny(404);
        }
        if ((int) $row['id_customer'] !== $idCustomer) {
            $this->deny(403);
        }

        // ---- Enforce approval / blocking rules ----
        $status = Tools::strtolower((string) $row['status']);
        if ($status !== 'approved') {
            // Rejected/pending/anything else: no download
            $this->deny(403);
        }

        // ---- Resolve storage root (outside public_html by default) ----
        $base = (string) Configuration::get('LRO_STORAGE_DIR');
        if ($base === '' || !is_dir($base)) {
            $base = _PS_ROOT_DIR_ . '/uploads_lrofileupload';
        }
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }
        $base = rtrim($base, '/');

        $names = [];
        if (!empty($row['file_name'])) {
            $names[] = basename((string) $row['file_name']);
        }
        if (!empty($row['original_name'])) {
            $names[] = basename((string) $row['original_name']);
        }
        $names = array_values(array_unique($names));

        $idCus = (int) $row['id_customer'];
        $idGrp = (int) $row['id_group'];
        $idReq = (int) $row['id_requirement'];

        $subpaths = ['', "/customer_{$idCus}", "/{$idCus}"];
        if ($idGrp) {
            $subpaths = array_merge($subpaths, ["/group_{$idGrp}", "/{$idCus}/{$idGrp}"]);
        }
        if ($idReq) {
            $subpaths = array_merge($subpaths, [
                "/req_{$idReq}",
                "/group_{$idGrp}/req_{$idReq}",
                "/{$idCus}/req_{$idReq}",
                "/{$idCus}/{$idGrp}/req_{$idReq}",
            ]);
        }

        $resolved = null;
        foreach ($subpaths as $sub) {
            foreach ($names as $n) {
                $try = $base . $sub . '/' . $n;
                if (is_file($try) && is_readable($try)) {
                    $resolved = $try;
                    break 2;
                }
            }
        }

        if (!$resolved) {
            foreach ([$row['file_name'], $row['original_name']] as $abs) {
                if (is_string($abs) && $abs !== '' && $abs[0] === '/' && is_file($abs) && is_readable($abs)) {
                    $resolved = $abs;
                    break;
                }
            }
        }

        if (!$resolved) {
            $this->deny(404);
        }

        // ---- Recording number text ----
        $recNo = $this->computeRecordingNumber($row); // e.g. "Recording No: 12345"
        $ext   = Tools::strtolower(pathinfo($resolved, PATHINFO_EXTENSION));

        // ---- Produce a stamped copy (and cache it) ----
        $stamped = $this->makeStamped($resolved, $recNo, $ext);
        if (!$stamped) {
            // Could not stamp (missing libs). Serve original but mark via header.
            header('X-Stamp-Missing: 1');
            $stamped = $resolved;
        }

        // ---- Stream stamped file ----
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $downloadName = $row['original_name'] ? (string) $row['original_name'] : basename($stamped);
        $mime         = $this->detectMime($stamped, $downloadName);
        $disposition  = (stripos($mime, 'pdf') !== false) ? 'inline' : 'attachment';

        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-cache, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Content-Type: ' . $mime);

        $ascii = str_replace(['"', '\\'], '_', $downloadName);
        header(
            'Content-Disposition: ' . $disposition .
            '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName)
        );

        $size = @filesize($stamped);
        if ($size !== false) {
            header('Content-Length: ' . $size);
        }

        $fp = @fopen($stamped, 'rb');
        if ($fp) {
            fpassthru($fp);
            fclose($fp);
        } else {
            readfile($stamped);
        }
        exit;
    }

    /* ==================== helpers ==================== */

    private function computeRecordingNumber(array $row): string
    {
        foreach (['recording_number', 'order_ref', 'order_reference'] as $k) {
            if (isset($row[$k]) && (string) $row[$k] !== '') {
                return 'Recording No: ' . (string) $row[$k];
            }
        }
        return 'Recording No: LRO-' . $row['id_upload'];
    }

    private function cacheDir(): string
    {
        $dir = _PS_ROOT_DIR_ . '/uploads_lrofileupload/.stamped';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private function makeStamped(string $src, string $text, string $ext): ?string
    {
        $hash = sha1($src . '|' . $text . '|' . @filemtime($src));
        $dst  = $this->cacheDir() . '/' . $hash . '.' . ($ext === 'pdf' ? 'pdf' : $ext);

        if (is_file($dst)) {
            return $dst;
        }

        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            return $this->stampImageGD($src, $dst, $text) ? $dst : null;
        }
        if ($ext === 'pdf') {
            // Try FPDI first, then Imagick
            if ($this->stampPdfFpdi($src, $dst, $text)) {
                return $dst;
            }
            if ($this->stampPdfImagick($src, $dst, $text)) {
                return $dst;
            }
            return null;
        }
        // Unknown type -> no stamp
        return null;
    }

    // === image stamping with GD ===
    private function stampImageGD(string $src, string $dst, string $text): bool
    {
        if (!function_exists('imagecreatefromstring')) {
            return false;
        }
        $data = @file_get_contents($src);
        if ($data === false) {
            return false;
        }

        $im = @imagecreatefromstring($data);
        if (!$im) {
            return false;
        }

        $w = imagesx($im);
        $h = imagesy($im);

        $padX     = 14;
        $padY     = 8;
        $fontSize = max(10, (int) round(min($w, $h) * 0.022));
        $fontFile = __DIR__ . '/../../views/fonts/DejaVuSans.ttf';
        $useTTF   = is_file($fontFile) && function_exists('imagettfbbox');

        $bboxW = 0;
        $bboxH = 0;
        if ($useTTF) {
            $bb    = imagettfbbox($fontSize, 0, $fontFile, $text);
            $bboxW = abs($bb[4] - $bb[0]);
            $bboxH = abs($bb[5] - $bb[1]);
        } else {
            $bboxW = strlen($text) * 7;
            $bboxH = 14;
        }

        $pillW = $bboxW + $padX * 2;
        $pillH = $bboxH + $padY * 2;
        $x     = $w - $pillW - 18;
        $y     = 18;

        $overlay = imagecreatetruecolor($pillW, $pillH);
        imagesavealpha($overlay, true);
        $clear = imagecolorallocatealpha($overlay, 0, 0, 0, 127);
        imagefill($overlay, 0, 0, $clear);
        $bg = imagecolorallocatealpha($overlay, 14, 165, 233, 80);
        $this->roundedRect($overlay, 0, 0, $pillW, $pillH, 12, $bg);

        $txtCol = imagecolorallocate($overlay, 11, 18, 32);
        if ($useTTF) {
            imagettftext($overlay, $fontSize, 0, $padX, $padY + $bboxH, $txtCol, $fontFile, $text);
        } else {
            imagestring($overlay, 3, $padX, (int) (($pillH - $bboxH) / 2), $text, $txtCol);
        }

        imagecopy($im, $overlay, $x, $y, 0, 0, $pillW, $pillH);

        $extOut = Tools::strtolower(pathinfo($dst, PATHINFO_EXTENSION));
        $ok     = false;
        switch ($extOut) {
            case 'jpg':
            case 'jpeg':
                $ok = imagejpeg($im, $dst, 92);
                break;
            case 'png':
                $ok = imagepng($im, $dst, 6);
                break;
            case 'gif':
                $ok = imagegif($im, $dst);
                break;
            case 'webp':
                if (function_exists('imagewebp')) {
                    $ok = imagewebp($im, $dst, 90);
                }
                break;
            default:
                $ok = false;
        }
        imagedestroy($overlay);
        imagedestroy($im);
        return (bool) $ok;
    }

    private function roundedRect($im, $x, $y, $w, $h, $r, $col): void
    {
        imagefilledrectangle($im, $x + $r, $y, $x + $w - $r, $y + $h, $col);
        imagefilledrectangle($im, $x, $y + $r, $x + $w, $y + $h - $r, $col);
        imagefilledellipse($im, $x + $r, $y + $r, $r * 2, $r * 2, $col);
        imagefilledellipse($im, $x + $w - $r, $y + $r, $r * 2, $r * 2, $col);
        imagefilledellipse($im, $x + $r, $y + $h - $r, $r * 2, $r * 2, $col);
        imagefilledellipse($im, $x + $w - $r, $y + $h - $r, $r * 2, $r * 2, $col);
    }

    private function stampPdfFpdi(string $src, string $dst, string $text): bool
    {
        if (!class_exists('\setasign\Fpdi\Fpdi')) {
            return false;
        }

        try {
            if (class_exists('\setasign\Fpdi\Fpdi') && class_exists('FPDF')) {
                $pdf       = new \setasign\Fpdi\Fpdi();
                $pageCount = $pdf->setSourceFile($src);
                for ($p = 1; $p <= $pageCount; $p++) {
                    $tpl  = $pdf->importPage($p);
                    $size = $pdf->getTemplateSize($tpl);
                    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $pdf->useTemplate($tpl);

                    $margin = 8;
                    $pdf->SetFont('Arial', '', 10);
                    $txtW = $pdf->GetStringWidth($text) + 6;
                    $txtH = 6 + 2;
                    $x    = $size['width'] - $txtW - $margin;
                    $y    = $margin;

                    $pdf->SetFillColor(14, 165, 233);
                    $pdf->SetDrawColor(14, 165, 233);
                    $pdf->RoundedRect($x, $y, $txtW, $txtH, 2, 'F');

                    $pdf->SetTextColor(11, 18, 32);
                    $pdf->SetXY($x + 3, $y + 2);
                    $pdf->Cell($txtW - 6, 5, $text, 0, 0, 'L');
                }
                return (bool) $pdf->Output($dst, 'F');
            }
        } catch (\Throwable $e) {
            // ignore, fallback to Imagick
        }
        return false;
    }

    private function stampPdfImagick(string $src, string $dst, string $text): bool
    {
        if (!class_exists('Imagick')) {
            return false;
        }
        try {
            $imagick = new \Imagick();
            $imagick->setResolution(144, 144);
            $imagick->readImage($src);

            $draw = new \ImagickDraw();
            $draw->setFontSize(16);
            $draw->setFillColor(new \ImagickPixel('rgb(11,18,32)'));
            $padX = 8;
            $padY = 6;

            foreach ($imagick as $page) {
                $w = $page->getImageWidth();
                $h = $page->getImageHeight();

                $metrics = $page->queryFontMetrics($draw, $text);
                $tw      = (int) ceil($metrics['textWidth']);
                $th      = (int) ceil($metrics['textHeight']);
                $pillW   = $tw + $padX * 2;
                $pillH   = $th + $padY * 2;

                $overlay = new \Imagick();
                $overlay->newImage($pillW, $pillH, new \ImagickPixel('transparent'), 'png');

                $od = new \ImagickDraw();
                $od->setFillColor(new \ImagickPixel('rgba(14,165,233,0.78)'));
                $od->roundRectangle(0, 0, $pillW - 1, $pillH - 1, 8, 8);
                $overlay->drawImage($od);

                $td = new \ImagickDraw();
                $td->setFillColor(new \ImagickPixel('rgb(11,18,32)'));
                $td->setFontSize(16);
                $overlay->annotateImage($td, $padX, $padY + $th - 3, 0, $text);

                $x = $w - $pillW - 16;
                $y = 16;
                $page->compositeImage($overlay, \Imagick::COMPOSITE_OVER, $x, $y);
            }

            $imagick->setImageFormat('pdf');
            $ok = $imagick->writeImages($dst, true);
            $imagick->clear();
            $imagick->destroy();
            return (bool) $ok;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function detectMime(string $path, string $name): string
    {
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $m = finfo_file($fi, $path);
                finfo_close($fi);
                if ($m) {
                    return $m;
                }
            }
        }
        $ext = Tools::strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            return 'application/pdf';
        }
        if (in_array($ext, ['jpg', 'jpeg'])) {
            return 'image/jpeg';
        }
        if ($ext === 'png') {
            return 'image/png';
        }
        if ($ext === 'gif') {
            return 'image/gif';
        }
        if ($ext === 'webp') {
            return 'image/webp';
        }
        return 'application/octet-stream';
    }

    private function deny(int $code): void
    {
        // No debug messages, just safe redirects
        if ($code === 403) {
            // For unauthorised, send to login if needed
            if (!$this->context->customer || !$this->context->customer->isLogged()) {
                Tools::redirect('index.php?controller=authentication');
                exit;
            }
        }
        // Soft 404 for all denied cases
        Tools::redirect('index.php?controller=404');
        exit;
    }
}

/* ===== FPDI helper: add RoundedRect to FPDF if missing ===== */
if (class_exists('FPDF') && !method_exists('FPDF', 'RoundedRect')) {
    class LroFpdfRR extends FPDF
    {
        public function RoundedRect($x, $y, $w, $h, $r, $style = '')
        {
            $k   = $this->k;
            $hp  = $this->h;
            $op  = $style == 'F' ? 'f' : ($style == 'FD' || $style == 'DF' ? 'B' : 'S');
            $MyArc = 4 / 3 * (sqrt(2) - 1);
            $this->_out(sprintf('%.2F %.2F m', ($x + $r) * $k, ($hp - $y) * $k));

            $xc = $x + $w - $r;
            $yc = $y + $r;
            $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - $y) * $k));
            $this->_Arc($xc + $r * $MyArc, $yc - $r, $xc + $r, $yc - $r * $MyArc, $xc + $r, $yc);

            $xc = $x + $w - $r;
            $yc = $y + $h - $r;
            $this->_out(sprintf('%.2F %.2F l', ($x + $w) * $k, ($hp - $yc) * $k));
            $this->_Arc($xc + $r, $yc + $r * $MyArc, $xc + $r * $MyArc, $yc + $r, $xc, $yc + $r);

            $xc = $x + $r;
            $yc = $y + $h - $r;
            $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - ($y + $h)) * $k));
            $this->_Arc($xc - $r * $MyArc, $yc + $r, $xc - $r, $yc + $r * $MyArc, $xc - $r, $yc);

            $xc = $x + $r;
            $yc = $y + $r;
            $this->_out(sprintf('%.2F %.2F l', $x * $k, ($hp - $yc) * $k));
            $this->_Arc($xc - $r, $yc - $r * $MyArc, $xc - $r * $MyArc, $yc - $r, $xc, $yc - $r);
            $this->_out($op);
        }

        public function _Arc($x1, $y1, $x2, $y2, $x3, $y3)
        {
            $h = $this->h;
            $this->_out(sprintf(
                '%.2F %.2F %.2F %.2F %.2F %.2F c',
                $x1 * $this->k,
                ($h - $y1) * $this->k,
                $x2 * $this->k,
                ($h - $y2) * $this->k,
                $x3 * $this->k,
                ($h - $y3) * $this->k
            ));
        }
    }
    class_alias('LroFpdfRR', 'FPDF');
}
