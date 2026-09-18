-- Spusťte v phpMyAdmin jednou (nebo pomocí MySQL klienta)
-- Přidá sloupce external_id a year do tel_vehicles

ALTER TABLE `tel_vehicles`
  ADD COLUMN `external_id` INT UNSIGNED DEFAULT NULL AFTER `spz_original`,
  ADD COLUMN `year`        SMALLINT UNSIGNED DEFAULT NULL AFTER `model`;
