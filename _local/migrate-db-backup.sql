-- Migrace: záloha databáze na S3
-- Spustit jednou v phpMyAdmin

INSERT IGNORE INTO tel_settings (setting_key, setting_value) VALUES
    ('s3_backup_prefix',  'backups'),
    ('db_backup_key',     ''),
    ('db_backup_log',     ''),
    ('db_last_backup',    ''),
    ('db_last_backup_file', ''),
    ('db_last_backup_size', '');
