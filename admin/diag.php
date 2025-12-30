<?php
require_once __DIR__ . '/../config/db.php';
header('Content-Type: text/plain; charset=utf-8');
echo "File: ".__FILE__."\n";
echo "PHP: ".PHP_VERSION."\n";
echo "settings.inc.php present: ".(defined('_DB_SERVER_')?'yes':'no')."\n";
echo "Host: "._DB_SERVER_."\nDB: "._DB_NAME_."\nUser: "._DB_USER_."\nPrefix: "._DB_PREFIX_."\n";
try {
  $r = $pdo->query('SELECT 1')->fetchColumn();
  echo "Simple query: $r\n";
} catch (Throwable $e) {
  echo "Query error: ".$e->getMessage()."\n";
}
