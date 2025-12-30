<?php
require_once __DIR__ . '/../config/db.php';

$prefix = defined('_DB_PREFIX_') ? _DB_PREFIX_ : 'ps_';
$tbl = $prefix . 'lrofileupload_admins';

$pdo->exec("
CREATE TABLE IF NOT EXISTS `$tbl` (
  `admin_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(80) NOT NULL UNIQUE,
  `email`    VARCHAR(190) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `is_master` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

$username = 'admin';
$email    = 'admin@example.local';
$pass     = 'LROadmin!23';

$exists = $pdo->prepare("SELECT 1 FROM `$tbl` WHERE username=? OR email=? LIMIT 1");
$exists->execute([$username, $email]);

if (!$exists->fetchColumn()) {
    $ins = $pdo->prepare("INSERT INTO `$tbl` (username,email,password_hash,is_master) VALUES (?,?,?,1)");
    $ins->execute([$username, $email, password_hash($pass, PASSWORD_DEFAULT)]);
    echo "Seeded master admin:\n  username: $username\n  password: $pass\n";
} else {
    echo "Admin already exists. No changes made.\n";
}
