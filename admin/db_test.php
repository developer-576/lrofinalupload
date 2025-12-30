<?php
require_once __DIR__ . '/../config/db.php';
try {
    $ok = $pdo->query("SELECT 1")->fetchColumn();
    header('Content-Type: text/plain');
    echo "OK: connected via PDO\nHost: " . _DB_SERVER_ . "\nDB:   " . _DB_NAME_ . "\nUser: " . _DB_USER_ . "\nPref: $prefix\nSimple query: $ok\n";
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'DB test failed: ' . $e->getMessage();
}
