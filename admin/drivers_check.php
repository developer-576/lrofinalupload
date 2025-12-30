<?php
header('Content-Type: text/plain');
echo "PDO present: " . (class_exists('PDO') ? 'yes' : 'no') . PHP_EOL;
echo "PDO drivers: " . (class_exists('PDO') ? implode(', ', PDO::getAvailableDrivers()) : '(none)') . PHP_EOL;
echo "mysqli present: " . (function_exists('mysqli_connect') ? 'yes' : 'no') . PHP_EOL;
