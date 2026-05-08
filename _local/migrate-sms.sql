-- Spusťte v phpMyAdmin nebo MySQL klientovi jednou
-- Přidá frontu SMS a nastavení pro TRB140

CREATE TABLE IF NOT EXISTS `tel_sms_queue` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` INT UNSIGNED DEFAULT NULL,
  `sent_by`    INT UNSIGNED NOT NULL,
  `phone`      VARCHAR(30)  NOT NULL,
  `message`    TEXT         NOT NULL,
  `status`     VARCHAR(20)  NOT NULL DEFAULT 'pending',
  `created_at` DATETIME     NOT NULL,
  `sent_at`    DATETIME     DEFAULT NULL,
  `error_msg`  VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_sms_status` (`status`),
  CONSTRAINT `fk_sms_request` FOREIGN KEY (`request_id`) REFERENCES `tel_requests`(`id`),
  CONSTRAINT `fk_sms_user`    FOREIGN KEY (`sent_by`)    REFERENCES `tel_users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `tel_settings` (`setting_key`, `setting_value`) VALUES
  ('sms_enabled',    '0'),
  ('sms_bridge_key', ''),
  ('trb140_ip',      ''),
  ('trb140_user',    'admin'),
  ('trb140_pass',    '');
