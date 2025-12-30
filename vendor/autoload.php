<?php
/**
 * Tiny autoloader for lrofileupload (no Composer).
 * It loads:
 *  - FPDF (classic) from vendor/setasign/fpdf/fpdf.php
 *  - FPDI 2.x (namespaced) by including its src/autoload.php
 *    even when the package folder is nested like FPDI-2.8.4/.
 *  - FPDI 1.x fallback (fpdi.php) if present.
 */

$base = __DIR__;

/** @return bool */
$req = static function (string $p): bool {
    if (is_file($p)) {
        require_once $p;
        return true;
    }
    return false;
};

/* ---- FPDF --------------------------------------------------------------- */
$loadedFpdf = $req($base . '/setasign/fpdf/fpdf.php');

/* ---- FPDI 2.x (namespaced) --------------------------------------------- */
// flat layout  .../setasign/fpdi/src/autoload.php
$loadedFpdi = $req($base . '/setasign/fpdi/src/autoload.php');

// nested layout .../setasign/fpdi/FPDI-x.y.z/src/autoload.php
if (!$loadedFpdi) {
    $globs = glob($base . '/setasign/fpdi/FPDI-*/src/autoload.php') ?: [];
    foreach ($globs as $file) {
        if ($req($file)) { $loadedFpdi = true; break; }
    }
}

// sometimes people call the dir "fpdi2"
if (!$loadedFpdi) {
    $loadedFpdi = $req($base . '/setasign/fpdi2/src/autoload.php');
}

/* ---- FPDI 1.x fallback (non-namespaced) -------------------------------- */
if (!$loadedFpdi) {
    $loadedFpdi = $req($base . '/setasign/fpdi/fpdi.php'); // legacy
}

// return something truthy so "require .../autoload.php" is harmless
return $loadedFpdf || $loadedFpdi;
