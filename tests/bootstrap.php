<?php
declare(strict_types=1);

// DB constants for the test database — set env vars to override
define('DB_HOST',    $_ENV['TEST_DB_HOST'] ?? getenv('TEST_DB_HOST') ?: '127.0.0.1');
define('DB_NAME',    $_ENV['TEST_DB_NAME'] ?? getenv('TEST_DB_NAME') ?: 'telefon_test');
define('DB_USER',    $_ENV['TEST_DB_USER'] ?? getenv('TEST_DB_USER') ?: 'root');
define('DB_PASS',    $_ENV['TEST_DB_PASS'] ?? getenv('TEST_DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// Stub constants required by functions.php / auth.php
define('APP_NAME',       'TestApp');
define('APP_URL',        'http://localhost');
define('SESSION_NAME',   'test_session');
define('LOG_FILE',       sys_get_temp_dir() . '/telefon_test.log');
define('MAIL_FROM',      'test@example.com');
define('MAIL_FROM_NAME', 'Test');

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once __DIR__ . '/DatabaseTestCase.php';

// Create all tables once at bootstrap time (IF NOT EXISTS — idempotent)
createTestSchema(getDB());

function createTestSchema(PDO $db): void
{
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');

    $statements = [
        "CREATE TABLE IF NOT EXISTS `tel_users` (
          `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `name`          VARCHAR(100) NOT NULL,
          `email`         VARCHAR(255) NOT NULL,
          `password_hash` VARCHAR(255) DEFAULT NULL,
          `role`          VARCHAR(20)  NOT NULL DEFAULT 'user',
          `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
          `can_reopen`    TINYINT(1)   NOT NULL DEFAULT 1,
          `created_at`    DATETIME     NOT NULL,
          `last_login`    DATETIME     DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `tel_requests` (
          `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
          `spz`             VARCHAR(20)   NOT NULL,
          `client_name`     VARCHAR(100)  NOT NULL,
          `client_phone`    VARCHAR(30)   DEFAULT NULL,
          `client_email`    VARCHAR(255)  DEFAULT NULL,
          `request_text`    TEXT          NOT NULL,
          `status`          VARCHAR(20)   NOT NULL DEFAULT 'new',
          `pending_reason`  TEXT          DEFAULT NULL,
          `reopen_reason`   TEXT          DEFAULT NULL,
          `created_by`      INT UNSIGNED  NOT NULL,
          `assigned_to_id`  INT UNSIGNED  DEFAULT NULL,
          `assigned_at`     DATETIME      DEFAULT NULL,
          `technician_note` TEXT          DEFAULT NULL,
          `created_at`      DATETIME      NOT NULL,
          `updated_at`      DATETIME      NOT NULL,
          `resolved_at`     DATETIME      DEFAULT NULL,
          `deleted_at`      DATETIME      DEFAULT NULL,
          PRIMARY KEY (`id`),
          CONSTRAINT `fk_req_created_by`  FOREIGN KEY (`created_by`)    REFERENCES `tel_users`(`id`),
          CONSTRAINT `fk_req_assigned_to` FOREIGN KEY (`assigned_to_id`) REFERENCES `tel_users`(`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `tel_request_history` (
          `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `request_id` INT UNSIGNED NOT NULL,
          `user_id`    INT UNSIGNED NOT NULL,
          `action`     VARCHAR(50)  NOT NULL,
          `field_name` VARCHAR(50)  DEFAULT NULL,
          `old_value`  TEXT         DEFAULT NULL,
          `new_value`  TEXT         DEFAULT NULL,
          `created_at` DATETIME     NOT NULL,
          PRIMARY KEY (`id`),
          CONSTRAINT `fk_hist_request` FOREIGN KEY (`request_id`) REFERENCES `tel_requests`(`id`),
          CONSTRAINT `fk_hist_user`    FOREIGN KEY (`user_id`)    REFERENCES `tel_users`(`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `tel_settings` (
          `setting_key`   VARCHAR(50) NOT NULL,
          `setting_value` TEXT        NOT NULL,
          PRIMARY KEY (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `tel_rate_limits` (
          `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `action`       VARCHAR(30)  NOT NULL,
          `ip_address`   VARCHAR(45)  NOT NULL,
          `email`        VARCHAR(255) DEFAULT NULL,
          `attempts`     TINYINT      NOT NULL DEFAULT 0,
          `locked_until` DATETIME     DEFAULT NULL,
          `last_attempt` DATETIME     NOT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `tel_sms_queue` (
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
          CONSTRAINT `fk_sms_request` FOREIGN KEY (`request_id`) REFERENCES `tel_requests`(`id`),
          CONSTRAINT `fk_sms_user`    FOREIGN KEY (`sent_by`)    REFERENCES `tel_users`(`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `tel_password_resets` (
          `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `user_id`    INT UNSIGNED NOT NULL,
          `token`      VARCHAR(64)  NOT NULL,
          `created_at` DATETIME     NOT NULL,
          `expires_at` DATETIME     NOT NULL,
          `used_at`    DATETIME     DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_token` (`token`),
          CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `tel_users`(`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `tel_vehicles` (
          `spz_normalized` VARCHAR(20)  NOT NULL,
          `spz_original`   VARCHAR(20)  NOT NULL,
          `vin`            VARCHAR(17)  DEFAULT NULL,
          `model`          VARCHAR(100) DEFAULT NULL,
          `updated_at`     DATETIME     NOT NULL,
          PRIMARY KEY (`spz_normalized`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($statements as $sql) {
        $db->exec($sql);
    }

    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
}
