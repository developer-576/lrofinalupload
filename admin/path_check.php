<?php
header('Content-Type: text/plain');
for ($up = 1; $up <= 6; $up++) {
    $candidate = dirname(__DIR__, $up) . '/config/settings.inc.php';
    echo $candidate . ' => ' . (is_file($candidate) ? 'FOUND' : 'miss') . PHP_EOL;
}
