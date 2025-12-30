<?php
use setasign\Fpdi\Fpdi;

class LruStampHelper
{
    /**
     * Stamp text on every page of a PDF file
     *
     * @param string $source Path to source PDF
     * @param string $dest Path to output PDF
     * @param string $text Text to stamp
     * @param int $fontSize Font size (optional)
     * @param array $color RGB color array (optional)
     * @param int $x X position (optional)
     * @param int $y Y position (optional)
     * @throws Exception
     */
    public static function stampPdf($source, $dest, $text, $fontSize = 14, $color = [255, 0, 0], $x = 150, $y = 10)
    {
        if (!file_exists($source)) {
            throw new Exception("Source PDF file does not exist: $source");
        }

        require_once _PS_MODULE_DIR_ . 'lrofileupload/lib/fpdf/fpdf.php';
        require_once _PS_MODULE_DIR_ . 'lrofileupload/lib/fpdi/autoload.php';

        $pdf = new Fpdi();
        $pageCount = $pdf->setSourceFile($source);

        for ($i = 1; $i <= $pageCount; $i++) {
            $tpl = $pdf->importPage($i);
            $pdf->AddPage();
            $pdf->useTemplate($tpl);
            $pdf->SetFont('Arial', 'B', $fontSize);
            $pdf->SetTextColor($color[0], $color[1], $color[2]);
            $pdf->SetXY($x, $y);
            $pdf->Write(10, $text);
        }
        $pdf->Output($dest, 'F');
    }

    /**
     * Stamp text on an image file
     *
     * @param string $source Path to source image
     * @param string $dest Path to output image
     * @param string $text Text to stamp
     * @param int $fontSize Font size (optional)
     * @param array $color RGB color array (optional)
     * @param int $x X position (optional)
     * @param int $y Y position (optional)
     */
    public static function stampImage($source, $dest, $text, $fontSize = 16, $color = [255, 0, 0], $x = 10, $y = 30)
    {
        if (!file_exists($source)) {
            throw new Exception("Source image file does not exist: $source");
        }

        $img = @imagecreatefromstring(file_get_contents($source));
        if (!$img) {
            // If image creation fails, just copy the source to destination
            copy($source, $dest);
            return;
        }

        $colorAllocated = imagecolorallocate($img, $color[0], $color[1], $color[2]);
        $fontPath = _PS_MODULE_DIR_ . 'lrofileupload/classes/arial.ttf';

        if (file_exists($fontPath)) {
            imagettftext($img, $fontSize, 0, $x, $y, $colorAllocated, $fontPath, $text);
        } else {
            imagestring($img, 5, $x, $y - 20, $text, $colorAllocated);
        }

        // Save image in the same format as source (jpeg or png)
        $info = getimagesize($source);
        if ($info['mime'] === 'image/png') {
            imagepng($img, $dest);
        } else {
            imagejpeg($img, $dest);
        }

        imagedestroy($img);
    }
}
