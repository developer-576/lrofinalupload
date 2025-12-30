CREATE TABLE IF NOT EXISTS `{PREFIX}lrofileupload_files` (
  `id_file` INT(11) NOT NULL AUTO_INCREMENT,
  `id_customer` INT(11) NOT NULL,
  `filename` VARCHAR(255) NOT NULL,
  `filepath` VARCHAR(255) NOT NULL,
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `date_uploaded` DATETIME NOT NULL,
  `id_group` INT(11) DEFAULT NULL,
  `id_requirement` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id_file`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{PREFIX}lrofileupload_admins` (
  `id_admin` INT(11) NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `is_master` TINYINT(1) NOT NULL DEFAULT 0,
  `last_login` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id_admin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{PREFIX}lrofileupload_groups` (
  `id_group` INT(11) NOT NULL AUTO_INCREMENT,
  `group_name` VARCHAR(255) NOT NULL,
  `id_product` INT(11) NOT NULL,
  PRIMARY KEY (`id_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{PREFIX}lrofileupload_requirements` (
  `id_requirement` INT(11) NOT NULL AUTO_INCREMENT,
  `id_group` INT(11) NOT NULL,
  `label` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`id_requirement`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{PREFIX}lrofileupload_rejection_reasons` (
  `id_reason` INT(11) NOT NULL AUTO_INCREMENT,
  `reason` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`id_reason`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{PREFIX}lrofileupload_unlocks` (
  `id_unlock` INT(11) NOT NULL AUTO_INCREMENT,
  `id_customer` INT(11) NOT NULL,
  `id_group` INT(11) NOT NULL,
  `date_add` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_unlock`),
  INDEX (`id_customer`, `id_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
