-- Spusťte v phpMyAdmin jednou
-- Přidá nastavení pro S3 synchronizaci vozidel

INSERT IGNORE INTO `tel_settings` (`setting_key`, `setting_value`) VALUES
  ('s3_region',              ''),
  ('s3_bucket',              ''),
  ('s3_object_key',          ''),
  ('s3_access_key_id',       ''),
  ('s3_secret_access_key',   ''),
  ('vehicles_sync_key',      ''),
  ('vehicles_last_sync',     ''),
  ('vehicles_last_sync_count', '0'),
  ('s3_last_etag',           ''),
  ('s3_file_last_modified',  ''),
  ('vehicles_sync_log',      '');
