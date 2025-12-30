<?php
/**
 * Autoloader for FPDI
 *
 * If you're using Composer, you don't need this file.
 */

spl_autoload_register(function ($class) {
    // Only autoload classes from the setasign\Fpdi namespace
    if (strpos($class, 'setasign\\Fpdi\\') === 0) {
        $classPath = __DIR__ . '/../' . str_replace('\\', '/', substr($class, strlen('setasign\\'))) . '.php';

        if (file_exists($classPath)) {
            require_once $classPath;
        }
    }
});
