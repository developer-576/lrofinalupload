-- Drop old incorrect tables if needed (comment these if you have data you want to keep)
-- DROP TABLE IF EXISTS `{PREFIX}lrofileupload_manual_unlocks`;
-- DROP TABLE IF EXISTS `{PREFIX}lrofileupload_uploads`;
-- DROP TABLE IF EXISTS `{PREFIX}lrofileupload_document_requirements`;
-- DROP TABLE IF EXISTS `{PREFIX}lrofileupload_product_groups`;
-- DROP TABLE IF EXISTS `{PREFIX}lrofileupload_groups`;

CREATE TABLE IF NOT EXISTS `{PREFIX}lrofileupload_groups` (
  `id_group`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_name` VARCHAR(255) NOT NULL,
  `active`     TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{PREFIX}lrofileupload_product_groups` (
  `id_group`   INT UNSIGNED NOT NULL,
  `id_product` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id_group`,`id_product`),
  KEY `idx_prod` (`id_product`),
  CONSTRAINT `fk_lro_pg_group`
    FOREIGN KEY (`id_group`) REFERENCES `{PREFIX}lrofileupload_groups` (`id_group`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{PREFIX}lrofileupload_document_requirements` (
  `id_requirement` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_group`       INT UNSIGNED NOT NULL,
  `document_name`  VARCHAR(255) NOT NULL,
  `file_type`      VARCHAR(64)  NOT NULL,  -- e.g. 'jpg', 'pdf', 'jpg,pdf'
  `required`       TINYINT(1) NOT NULL DEFAULT 0,
  `description`    TEXT NULL,
  `sort_order`     INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id_requirement`),
  KEY `idx_group` (`id_group`),
  CONSTRAINT `fk_lro_req_group`
    FOREIGN KEY (`id_group`) REFERENCES `{PREFIX}lrofileupload_groups` (`id_group`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{PREFIX}lrofileupload_uploads` (
  `id_upload`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_customer`    INT UNSIGNED NOT NULL,
  `id_group`       INT UNSIGNED NULL,
  `id_requirement` INT UNSIGNED NULL,
  `file_name`      VARCHAR(255) NOT NULL,
  `status`         ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `reason`         VARCHAR(255) NULL,
  `uploaded_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_upload`),
  KEY `idx_customer` (`id_customer`),
  KEY `idx_group` (`id_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{PREFIX}lrofileupload_manual_unlocks` (
  `id_unlock`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_customer` INT UNSIGNED NOT NULL,
  `id_group`    INT UNSIGNED NOT NULL,
  `unlocked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`  DATETIME NULL,
  `is_active`   TINYINT(1) NULL DEFAULT 1,
  PRIMARY KEY (`id_unlock`),
  UNIQUE KEY `uniq_customer_group` (`id_customer`,`id_group`),
  CONSTRAINT `fk_lro_unlock_group`
    FOREIGN KEY (`id_group`) REFERENCES `{PREFIX}lrofileupload_groups` (`id_group`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
