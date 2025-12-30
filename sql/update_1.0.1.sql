-- Add id_reason column to lrofileupload_files table
ALTER TABLE `{PREFIX}lrofileupload_files` 
ADD COLUMN `id_reason` INT(11) DEFAULT NULL AFTER `reason`,
ADD INDEX `idx_id_reason` (`id_reason`),
ADD FOREIGN KEY (`id_reason`) REFERENCES `{PREFIX}lrofileupload_reasons` (`id_reason`) ON DELETE SET NULL; 