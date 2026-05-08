# Databázové schéma – AutoBorek Tel

**Verze:** 1.0  
**Datum:** 2026-05-08  
**Prefix tabulek:** `tel_`  
**Charset:** `utf8mb4_unicode_ci`  
**Timezone v DB:** UTC (aplikace zobrazuje v Europe/Prague)

---

## Přehled tabulek

| Tabulka               | Popis                                           |
|-----------------------|-------------------------------------------------|
| `tel_users`           | Uživatelé systému                               |
| `tel_requests`        | Telefonické požadavky                           |
| `tel_request_history` | Audit log každé změny požadavku                 |
| `tel_settings`        | Konfigurace systému (key-value)                 |
| `tel_password_resets` | Tokeny pro reset/nastavení hesla                |
| `tel_rate_limits`     | Ochrana před brute-force útoky                  |
| `tel_vehicles`        | Evidence vozidel SPZ → VIN + model (v. 2.0)     |

---

## tel_users

```sql
CREATE TABLE `tel_users` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(100) NOT NULL,
  `email`         VARCHAR(255) NOT NULL,
  `password_hash` VARCHAR(255) DEFAULT NULL,    -- NULL = heslo ještě nenastaveno
  `role`          VARCHAR(20)  NOT NULL DEFAULT 'user',
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`    DATETIME     NOT NULL,
  `last_login`    DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email` (`email`)
) ENGINE=InnoDB;
```

| Sloupec | Poznámka |
|---|---|
| `role` | Povolené hodnoty: `admin`, `user` |
| `password_hash` | bcrypt, cost 12; NULL = nový uživatel bez hesla |
| `is_active` | 0 = blokován, 1 = aktivní |

---

## tel_requests

```sql
CREATE TABLE `tel_requests` (
  `id`                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `spz`               VARCHAR(20)   NOT NULL,
  `client_name`       VARCHAR(100)  NOT NULL,
  `client_phone`      VARCHAR(30)   DEFAULT NULL,
  `client_email`      VARCHAR(255)  DEFAULT NULL,
  `request_text`      TEXT          NOT NULL,
  `status`            VARCHAR(20)   NOT NULL DEFAULT 'new',
  `pending_reason`    TEXT          DEFAULT NULL,
  `reopen_reason`     TEXT          DEFAULT NULL,
  `created_by`        INT UNSIGNED  NOT NULL,
  `assigned_to_id`    INT UNSIGNED  DEFAULT NULL,
  `assigned_at`       DATETIME      DEFAULT NULL,
  `technician_note`   TEXT          DEFAULT NULL,
  `created_at`        DATETIME      NOT NULL,
  `updated_at`        DATETIME      NOT NULL,
  `resolved_at`       DATETIME      DEFAULT NULL,
  `deleted_at`        DATETIME      DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_req_status`   (`status`),
  INDEX `idx_req_created`  (`created_at`),
  INDEX `idx_req_assigned` (`assigned_to_id`),
  INDEX `idx_req_updated`  (`updated_at`),
  INDEX `idx_req_deleted`  (`deleted_at`),
  CONSTRAINT `fk_req_created_by`  FOREIGN KEY (`created_by`)     REFERENCES `tel_users`(`id`),
  CONSTRAINT `fk_req_assigned_to` FOREIGN KEY (`assigned_to_id`) REFERENCES `tel_users`(`id`)
) ENGINE=InnoDB;
```

### Povolené hodnoty `status`

| Hodnota | Popis |
|---|---|
| `new` | Nový, nepřevzatý |
| `in_progress` | Technik převzal, aktivně řeší |
| `pending` | Čeká (na díl, klienta, schválení) |
| `resolved` | Vyřízeno |
| `reopened` | Znovuotevřeno po vyřízení |

### Lifecycle stavů

```
new ──► in_progress ──► resolved ──► reopened ──► in_progress
              │
              ▼
           pending ──► in_progress
```

### Soft delete

- `deleted_at IS NULL` = aktivní záznam
- `deleted_at IS NOT NULL` = skrytý (soft-deleted), fyzická data zachována

---

## tel_request_history

```sql
CREATE TABLE `tel_request_history` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id`  INT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED NOT NULL,
  `action`      VARCHAR(50)  NOT NULL,
  `field_name`  VARCHAR(50)  DEFAULT NULL,
  `old_value`   TEXT         DEFAULT NULL,
  `new_value`   TEXT         DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_hist_request`      (`request_id`),
  INDEX `idx_hist_request_time` (`request_id`, `created_at`),
  INDEX `idx_hist_time`         (`created_at`),
  CONSTRAINT `fk_hist_request` FOREIGN KEY (`request_id`) REFERENCES `tel_requests`(`id`),
  CONSTRAINT `fk_hist_user`    FOREIGN KEY (`user_id`)    REFERENCES `tel_users`(`id`)
) ENGINE=InnoDB;
```

### Povolené hodnoty `action`

| Hodnota | Popis |
|---|---|
| `created` | Nový požadavek vytvořen |
| `status_change` | Změna stavu |
| `assigned` | Přidělení technikovi |
| `takeover` | Přebrání od jiného technika |
| `field_edit` | Editace konkrétního pole |
| `reopened` | Znovuotevření |
| `soft_deleted` | Soft delete adminem |
| `anonymized` | GDPR anonymizace |

Záznamy `old_value` / `new_value` jsou zkráceny na 500 znaků (delší s příponou `[zkráceno]`).

---

## tel_settings

```sql
CREATE TABLE `tel_settings` (
  `setting_key`   VARCHAR(50) NOT NULL,
  `setting_value` TEXT        NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB;
```

### Výchozí hodnoty

| Klíč | Výchozí | Popis |
|---|---|---|
| `refresh_interval` | `30` | Interval automatické aktualizace (sekund) |
| `color_level_1` | `15` | Práh úrovně 1→2 (minuty) |
| `color_level_2` | `30` | Práh úrovně 2→3 (minuty) |
| `color_level_3` | `60` | Práh úrovně 3→4 (minuty) |
| `color_level_4` | `120` | Práh úrovně 4→5 (minuty) |
| `session_timeout` | `500` | Session timeout nečinnosti (minuty) |

---

## tel_password_resets

```sql
CREATE TABLE `tel_password_resets` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `token`       VARCHAR(64)  NOT NULL,
  `created_at`  DATETIME     NOT NULL,
  `expires_at`  DATETIME     NOT NULL,
  `used_at`     DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token`),
  CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `tel_users`(`id`)
) ENGINE=InnoDB;
```

- Token je 64 hex znaků (32 náhodných bytů).
- Platnost: 24 hodin od `created_at`.
- `used_at IS NOT NULL` = token již byl použit nebo invalidován.
- Nový reset token invaliduje všechny předchozí (UPDATE `used_at = NOW()`).

---

## tel_rate_limits

```sql
CREATE TABLE `tel_rate_limits` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `action`       VARCHAR(30)  NOT NULL,
  `ip_address`   VARCHAR(45)  NOT NULL,
  `email`        VARCHAR(255) DEFAULT NULL,
  `attempts`     TINYINT      NOT NULL DEFAULT 0,
  `locked_until` DATETIME     DEFAULT NULL,
  `last_attempt` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_rl_action_ip`    (`action`, `ip_address`),
  INDEX `idx_rl_action_email` (`action`, `email`)
) ENGINE=InnoDB;
```

### Limity

| Akce | Max pokusů | Lockout |
|---|---|---|
| `login` | 5 / 15 min | 15 min (IP+email) |
| `reset` | 3 / hod | 60 min (email) |

Exponential backoff: každý další pokus po lockoutu prodlužuje lockout 2×.

---

## tel_vehicles

```sql
CREATE TABLE `tel_vehicles` (
  `spz_normalized` VARCHAR(20)  NOT NULL,
  `spz_original`   VARCHAR(20)  NOT NULL,
  `vin`            VARCHAR(17)  DEFAULT NULL,
  `model`          VARCHAR(100) DEFAULT NULL,
  `updated_at`     DATETIME     NOT NULL,
  PRIMARY KEY (`spz_normalized`)
) ENGINE=InnoDB;
```

Připraveno pro v. 2.0 — lookup SPZ → VIN + model z DMS (CSV import).  
`spz_normalized` = SPZ bez mezer a pomlček, uppercase (např. `1AB1234`).
