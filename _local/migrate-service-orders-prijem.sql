-- Spusťte v phpMyAdmin jednou (až PO migrate-service-orders.sql)
-- Objednávky mají dva zdrojové soubory (planovac-objednano.csv + planovac-prijem.csv);
-- sloupec `source` říká, ze kterého řádek pochází, a import nahrazuje jen řádky svého zdroje.

ALTER TABLE `tel_service_orders`
  ADD COLUMN `source` VARCHAR(20) NOT NULL DEFAULT 'objednano' AFTER `id`,
  ADD INDEX `idx_so_source` (`source`);

INSERT IGNORE INTO `tel_settings` (`setting_key`, `setting_value`) VALUES
  ('s3_prijem_object_key',         ''),
  ('s3_prijem_last_etag',          ''),
  ('s3_prijem_file_last_modified', '');
