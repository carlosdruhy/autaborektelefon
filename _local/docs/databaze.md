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
| `tel_vehicles`        | Evidence vozidel SPZ → model, rok, VIN (S3 sync) |
| `tel_sms_queue`       | Fronta odchozích SMS                            |
| `tel_service_orders`  | Objednávky do servisu (snímek plánovače z S3)   |

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
| `s3_region`, `s3_bucket`, `s3_access_key_id`, `s3_secret_access_key` | — | Společné S3 přihlašovací údaje (vozidla, objednávky, zálohy) |
| `vehicles_sync_key` | — | Klíč cron endpointu `api/sync-vehicles.php` (vozidla + objednávky) |
| `s3_object_key`, `s3_last_etag`, `s3_file_last_modified` | — | Soubor vozidel `export-spz.csv` |
| `vehicles_last_sync`, `vehicles_last_sync_count`, `vehicles_sync_log` | — | Stav a JSON protokol synchronizace vozidel (posledních 25) |
| `s3_orders_object_key`, `s3_orders_last_etag`, `s3_orders_file_last_modified` | — | Soubor `planovac-objednano.csv` |
| `s3_prijem_object_key`, `s3_prijem_last_etag`, `s3_prijem_file_last_modified` | — | Soubor `planovac-prijem.csv` |
| `orders_last_sync`, `orders_last_sync_count`, `orders_sync_log` | — | Stav (počet = celkem řádků v tabulce) a JSON protokol synchronizace objednávek |

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
  `spz_normalized` VARCHAR(20)       NOT NULL,
  `spz_original`   VARCHAR(20)       NOT NULL,
  `external_id`    INT UNSIGNED      DEFAULT NULL,
  `model`          VARCHAR(100)      DEFAULT NULL,
  `year`           SMALLINT UNSIGNED DEFAULT NULL,
  `vin`            VARCHAR(17)       DEFAULT NULL,
  `updated_at`     DATETIME          NOT NULL,
  PRIMARY KEY (`spz_normalized`)
) ENGINE=InnoDB;
```

Lookup SPZ → model, rok, VIN. Plní se ze S3 souboru `export-spz.csv` (`klic;spz;nazvoz;rokvyr;vin`) přes `importVehiclesCsv()` — UPSERT podle `spz_normalized`.  
`spz_normalized` = SPZ bez mezer a pomlček, uppercase (např. `1AB1234`).

---

## tel_service_orders

```sql
CREATE TABLE `tel_service_orders` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source`         VARCHAR(20)  NOT NULL DEFAULT 'objednano',
  `scheduled_at`   DATETIME     NOT NULL,
  `spz_normalized` VARCHAR(20)  DEFAULT NULL,
  `spz_original`   VARCHAR(20)  DEFAULT NULL,
  `vin`            VARCHAR(17)  DEFAULT NULL,
  `client_name`    VARCHAR(100) DEFAULT NULL,
  `imported_at`    DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_so_spz`       (`spz_normalized`),
  INDEX `idx_so_vin`       (`vin`),
  INDEX `idx_so_scheduled` (`scheduled_at`),
  INDEX `idx_so_source`    (`source`)
) ENGINE=InnoDB;
```

Objednávky do servisu z plánovače DMS. Dva zdrojové soubory na S3 se stejnou strukturou `datum_zac;spz;fabkod;vinkod;klient`:

| `source`    | Soubor                    |
|-------------|---------------------------|
| `objednano` | `planovac-objednano.csv`  |
| `prijem`    | `planovac-prijem.csv`     |

- `vin` = `fabkod` + `vinkod` (3 + 14 znaků), validace `[A-HJ-NPR-Z0-9]{17}`, jinak NULL.
- `scheduled_at` je UTC; zdrojový `datum_zac` je lokální čas Europe/Prague (`pragueToUtc()`).
- Tabulka je **snímek**: každý import (`importServiceOrdersCsv($db, $path, $source)`) v jedné transakci smaže řádky svého `source` a vloží nové. Žádný unikátní klíč; stejná objednávka bývá v obou souborech (dedup až při zobrazení).
- Řádek bez SPZ i bez platného VIN se při importu přeskočí.
- Migrace: `_local/migrate-service-orders.sql`, `_local/migrate-service-orders-prijem.sql`.
