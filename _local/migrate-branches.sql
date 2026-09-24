-- Spusťte v phpMyAdmin jednou (PRD 1.8 – pobočky)
-- Vytvoří číselník poboček, přiřazení uživatelů k pobočkám a pobočku u požadavků.
-- Všechny existující požadavky i uživatelé se přiřadí k pobočce „Borek – servis“;
-- aplikace po migraci funguje stejně jako dosud. Názvy a kódy poboček lze upravit
-- v administraci (Pobočky). Aktivní je zatím jen BOREK; LAKOVNA a TABOR se zapnou
-- v administraci tlačítkem Aktivovat, až budou připravené.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `tel_branches` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`            VARCHAR(20)  NOT NULL,
  `name`            VARCHAR(100) NOT NULL,
  `dms_center_code` VARCHAR(20)  DEFAULT NULL,
  `is_active`       TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`      SMALLINT     NOT NULL DEFAULT 0,
  `created_at`      DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branch_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tel_branches` (`id`, `code`, `name`, `dms_center_code`, `is_active`, `sort_order`, `created_at`) VALUES
  (1, 'BOREK',   'Borek – servis',  '3',  1, 10, UTC_TIMESTAMP()),
  (2, 'LAKOVNA', 'Borek – lakovna', '3',  0, 20, UTC_TIMESTAMP()),
  (3, 'TABOR',   'Tábor – servis',  '33', 0, 30, UTC_TIMESTAMP());

CREATE TABLE IF NOT EXISTS `tel_user_branches` (
  `user_id`   INT UNSIGNED NOT NULL,
  `branch_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`user_id`, `branch_id`),
  INDEX `idx_ub_branch` (`branch_id`),
  CONSTRAINT `fk_ub_user`   FOREIGN KEY (`user_id`)   REFERENCES `tel_users`(`id`),
  CONSTRAINT `fk_ub_branch` FOREIGN KEY (`branch_id`) REFERENCES `tel_branches`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Požadavky: nejdřív NULL, naplnit výchozí pobočkou, pak NOT NULL + FK + index
ALTER TABLE `tel_requests` ADD COLUMN `branch_id` INT UNSIGNED NULL AFTER `status`;
UPDATE `tel_requests` SET `branch_id` = 1 WHERE `branch_id` IS NULL;
ALTER TABLE `tel_requests`
  MODIFY `branch_id` INT UNSIGNED NOT NULL,
  ADD INDEX `idx_req_branch` (`branch_id`, `status`),
  ADD CONSTRAINT `fk_req_branch` FOREIGN KEY (`branch_id`) REFERENCES `tel_branches`(`id`);

-- Uživatelé: domovská pobočka + přiřazení všech ke výchozí pobočce
ALTER TABLE `tel_users`
  ADD COLUMN `default_branch_id` INT UNSIGNED NULL AFTER `can_reopen`,
  ADD CONSTRAINT `fk_user_default_branch` FOREIGN KEY (`default_branch_id`) REFERENCES `tel_branches`(`id`);
INSERT IGNORE INTO `tel_user_branches` (`user_id`, `branch_id`) SELECT `id`, 1 FROM `tel_users`;
UPDATE `tel_users` SET `default_branch_id` = 1 WHERE `default_branch_id` IS NULL;

-- Objednávky plánovače: kód střediska DMS (sloupec `stredisko` v CSV)
ALTER TABLE `tel_service_orders`
  ADD COLUMN `center_code` VARCHAR(20) NULL AFTER `client_name`,
  ADD INDEX `idx_so_center` (`center_code`);
