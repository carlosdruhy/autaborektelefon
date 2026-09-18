-- Spusťte v phpMyAdmin jednou
-- Přidá tabulku objednávek do servisu (snímek z planovac-objednano.csv na S3)
-- a nastavení pro její synchronizaci.

CREATE TABLE IF NOT EXISTS `tel_service_orders` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scheduled_at`   DATETIME     NOT NULL,
  `spz_normalized` VARCHAR(20)  DEFAULT NULL,
  `spz_original`   VARCHAR(20)  DEFAULT NULL,
  `vin`            VARCHAR(17)  DEFAULT NULL,
  `client_name`    VARCHAR(100) DEFAULT NULL,
  `imported_at`    DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_so_spz`       (`spz_normalized`),
  INDEX `idx_so_vin`       (`vin`),
  INDEX `idx_so_scheduled` (`scheduled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `tel_settings` (`setting_key`, `setting_value`) VALUES
  ('s3_orders_object_key',         ''),
  ('s3_orders_last_etag',          ''),
  ('s3_orders_file_last_modified', ''),
  ('orders_last_sync',             ''),
  ('orders_last_sync_count',       '0'),
  ('orders_sync_log',              '');
