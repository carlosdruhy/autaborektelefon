# Implementační plán – AutoBorek Tel

**Verze:** 1.0  
**Datum:** 2026-05-08  
**Vychází z PRD:** v1.2  

---

## Přehled fází

| Fáze | Název                        | Výstup                                          | Závislost  |
|------|------------------------------|-------------------------------------------------|------------|
| 1    | Databáze a infrastruktura    | DB schema, sdílené PHP soubory, .htaccess       | —          |
| 2    | Autentizace                  | Login, logout, reset hesla, session             | Fáze 1     |
| 3    | API – Požadavky              | CRUD + audit log + transakce                    | Fáze 1, 2  |
| 4    | API – Nastavení a statistiky | Čtení config, statistické agregace              | Fáze 1, 2  |
| 5    | Dashboard – HTML + CSS       | Statická struktura, styl, karty                 | Fáze 2     |
| 6    | Dashboard – JavaScript       | Auto-refresh, modal, notifikace, search         | Fáze 3, 4, 5 |
| 7    | Admin – Backend API          | CRUD uživatelů (JSON)                           | Fáze 1, 2  |
| 8    | Admin – Frontend             | Stránky správy uživatelů, nastavení, statistik  | Fáze 4, 7  |
| 9    | Integrace a hardening        | Rate limiting, security headers, install lock   | Fáze 1–8   |
| 10   | Dokumentace                  | Instalační příručka, DB schéma                  | Fáze 1–9   |

---

## Fáze 1 – Databáze a infrastruktura

**Cíl:** Funkční DB připojení, struktura tabulek, základní pomocné funkce, HTTP vrstva.

### 1.1 `install.php`

Jednorázový instalační skript. Spustí se jednou a vytvoří `install.lock`.

**Kroky:**
1. Kontrola verze PHP (>= 8.0) a existence extenze PDO + pdo_mysql.
2. Kontrola existence `install.lock` → pokud existuje, zobrazí chybu a ukončí se.
3. Načtení `includes/config.php`, pokus o připojení k DB.
4. Spuštění DDL (CREATE TABLE IF NOT EXISTS) pro všechny tabulky (viz sekce SQL níže).
5. Vložení výchozích hodnot do `tel_settings`.
6. Interaktivní formulář: jméno admina, e-mail, heslo → INSERT do `tel_users`.
7. Vytvoření souboru `install.lock`.
8. Zobrazení instrukce: „Instalace dokončena. Přejděte na /login.php".

**SQL — CREATE TABLE:**

```sql
CREATE TABLE IF NOT EXISTS `tel_users` (
  `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(100)    NOT NULL,
  `email`         VARCHAR(255)    NOT NULL,
  `password_hash` VARCHAR(255)    DEFAULT NULL,
  `role`          VARCHAR(20)     NOT NULL DEFAULT 'user',
  `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`    DATETIME        NOT NULL,
  `last_login`    DATETIME        DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tel_requests` (
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
  CONSTRAINT `fk_req_created_by`   FOREIGN KEY (`created_by`)     REFERENCES `tel_users`(`id`),
  CONSTRAINT `fk_req_assigned_to`  FOREIGN KEY (`assigned_to_id`) REFERENCES `tel_users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tel_request_history` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `request_id`  INT UNSIGNED  NOT NULL,
  `user_id`     INT UNSIGNED  NOT NULL,
  `action`      VARCHAR(50)   NOT NULL,
  `field_name`  VARCHAR(50)   DEFAULT NULL,
  `old_value`   TEXT          DEFAULT NULL,
  `new_value`   TEXT          DEFAULT NULL,
  `created_at`  DATETIME      NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_hist_request`      (`request_id`),
  INDEX `idx_hist_request_time` (`request_id`, `created_at`),
  INDEX `idx_hist_time`         (`created_at`),
  CONSTRAINT `fk_hist_request` FOREIGN KEY (`request_id`) REFERENCES `tel_requests`(`id`),
  CONSTRAINT `fk_hist_user`    FOREIGN KEY (`user_id`)    REFERENCES `tel_users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tel_settings` (
  `setting_key`   VARCHAR(50)   NOT NULL,
  `setting_value` TEXT          NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tel_password_resets` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED  NOT NULL,
  `token`       VARCHAR(64)   NOT NULL,
  `created_at`  DATETIME      NOT NULL,
  `expires_at`  DATETIME      NOT NULL,
  `used_at`     DATETIME      DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token`),
  CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `tel_users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tel_rate_limits` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `action`       VARCHAR(30)   NOT NULL,
  `ip_address`   VARCHAR(45)   NOT NULL,
  `email`        VARCHAR(255)  DEFAULT NULL,
  `attempts`     TINYINT       NOT NULL DEFAULT 0,
  `locked_until` DATETIME      DEFAULT NULL,
  `last_attempt` DATETIME      NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_rl_action_ip`    (`action`, `ip_address`),
  INDEX `idx_rl_action_email` (`action`, `email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tel_vehicles` (
  `spz_normalized` VARCHAR(20)   NOT NULL,
  `spz_original`   VARCHAR(20)   NOT NULL,
  `vin`            VARCHAR(17)   DEFAULT NULL,
  `model`          VARCHAR(100)  DEFAULT NULL,
  `updated_at`     DATETIME      NOT NULL,
  PRIMARY KEY (`spz_normalized`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Výchozí nastavení
INSERT IGNORE INTO `tel_settings` (`setting_key`, `setting_value`) VALUES
  ('refresh_interval',   '30'),
  ('color_level_1',      '15'),
  ('color_level_2',      '30'),
  ('color_level_3',      '60'),
  ('color_level_4',      '120'),
  ('session_timeout',    '500');
```

---

### 1.2 `includes/config.php`

Konstanty: DB přihlašovací údaje, `APP_URL`, `APP_NAME`, `MAIL_FROM`, `MAIL_FROM_NAME`, `SESSION_NAME`.  
`date_default_timezone_set('Europe/Prague')`.  
Žádná logika — jen `define()`.

---

### 1.3 `includes/db.php`

Funkce `getDB(): PDO` — singleton. DSN s `charset=utf8mb4`. Atributy:
- `ERRMODE_EXCEPTION`
- `FETCH_ASSOC`
- `EMULATE_PREPARES = false`

Po připojení: `SET time_zone = '+00:00'` (MySQL pracuje s UTC).

---

### 1.4 `includes/functions.php`

Pomocné funkce bez vedlejších efektů.

| Funkce | Popis |
|--------|-------|
| `getSettings(): array` | Načte všechny řádky `tel_settings`, vrátí jako asociativní pole |
| `getSetting(string $key, mixed $default = null): mixed` | Jeden klíč z nastavení |
| `setSetting(string $key, string $value): void` | REPLACE INTO tel_settings |
| `jsonOk(mixed $data = null): never` | `header Content-Type: application/json`, `echo json_encode(...)`, `exit` |
| `jsonErr(string $msg, int $code = 400): never` | Jako jsonOk, ale s HTTP kódem a `success: false` |
| `getPostedJson(): array` | Načte `php://input`, dekóduje JSON, vrátí pole (nebo prázdné pole) |
| `h(string $s): string` | `htmlspecialchars($s, ENT_QUOTES, 'UTF-8')` |
| `nowUtc(): string` | `gmdate('Y-m-d H:i:s')` |
| `toLocalTime(string $utc): string` | Převod UTC → `Europe/Prague`, formát `d.m.Y H:i` |
| `ageMinutes(string $utcDatetime): int` | Minuty od daného UTC času do teď |
| `normalizeSpz(string $spz): string` | Odstraní mezery, pomlčky, převede na uppercase |
| `truncateForLog(string $s, int $max = 500): string` | Zkrátí text pro uložení do history logu |
| `logAudit(PDO $db, int $requestId, int $userId, string $action, ?string $field = null, ?string $old = null, ?string $new = null): void` | INSERT do `tel_request_history` — volá se uvnitř transakce |
| `checkRateLimit(string $action, string $ip, string $email = ''): bool` | Vrátí `true` = povoleno, `false` = zablokováno; implementuje IP+email combo + exponential backoff |
| `recordRateFail(string $action, string $ip, string $email = ''): void` | Inkrementuje pokus, nastaví `locked_until` |

---

### 1.5 `.htaccess` (root)

```apache
Options -Indexes

# Bezpečnostní hlavičky
<IfModule mod_headers.c>
    Header always set X-Frame-Options "DENY"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "same-origin"
    Header always set Content-Security-Policy "default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; font-src 'self' https://cdn.jsdelivr.net"
</IfModule>

# Zakaz přístupu do interních složek
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^includes/ - [F,L]
    RewriteRule ^logs/     - [F,L]
</IfModule>
```

### 1.6 `admin/.htaccess`

```apache
Options -Indexes
```

---

## Fáze 2 – Autentizace

**Cíl:** Funkční přihlášení, odhlášení, reset hesla, ochrana stránek rolí.

### 2.1 `includes/auth.php`

| Funkce | Popis |
|--------|-------|
| `startSecureSession(): void` | Nastaví cookie params (HttpOnly, Secure, SameSite=Strict), spustí session. Voláno na začátku každé stránky. |
| `isLoggedIn(): bool` | Kontrola `$_SESSION['user_id']` |
| `isAdmin(): bool` | `isLoggedIn() && $_SESSION['user_role'] === 'admin'` |
| `requireLogin(): void` | Pokud ne → `jsonErr(401)` pro API, jinak redirect na login.php |
| `requireAdmin(): void` | Pokud ne admin → `jsonErr(403)` pro API, jinak redirect |
| `currentUserId(): int` | `(int)$_SESSION['user_id']` |
| `currentUserName(): string` | `$_SESSION['user_name']` |
| `currentUserRole(): string` | `$_SESSION['user_role']` |
| `loginUser(array $user): void` | Regenerate session ID, naplní `$_SESSION`, aktualizuje `last_login` a `last_activity` v DB |
| `checkSessionTimeout(): void` | Porovná `$_SESSION['last_activity']` s `getSetting('session_timeout')`. Pokud vypršelo → destroySession() + redirect/401 |
| `touchSession(): void` | Aktualizuje `$_SESSION['last_activity']` = čas teď |
| `destroySession(): void` | `session_destroy()`, smaže cookie |
| `generateToken(int $bytes = 32): string` | `bin2hex(random_bytes($bytes))` |
| `verifyCsrf(): void` | Porovná `$_SERVER['HTTP_X_CSRF_TOKEN']` se session tokenem. Nesoulad → `jsonErr(403, 'CSRF')` |
| `sendPasswordResetEmail(array $user, string $token): bool` | Sestaví reset URL, odešle `mail()` s plaintext tělem. Vrátí výsledek `mail()`. |

**Volání na každé chráněné stránce / API:**
```php
startSecureSession();
requireLogin();       // nebo requireAdmin()
checkSessionTimeout();
touchSession();
```

---

### 2.2 `index.php`

```php
startSecureSession();
header('Location: ' . (isLoggedIn() ? 'dashboard.php' : 'login.php'));
exit;
```

---

### 2.3 `login.php`

- GET: zobrazí formulář (e-mail + heslo).
- POST:
  1. `checkRateLimit('login', $ip, $email)` — pokud zamknuto → zobraz chybu s čekací dobou.
  2. Načti uživatele dle e-mailu (`is_active = 1`).
  3. `password_verify()` — pokud selhání → `recordRateFail()`, zobraz chybu.
  4. Pokud heslo NULL (nový uživatel) → zobraz: „Účet zatím nemá heslo. Použijte Zapomenuté heslo."
  5. Úspěch → `loginUser()`, redirect na dashboard.
- CSRF: běžný `<input hidden>` v HTML formuláři (ne header — login není ajaxové).

---

### 2.4 `logout.php`

```php
startSecureSession();
destroySession();
header('Location: login.php');
exit;
```

---

### 2.5 `forgot-password.php`

- GET: formulář s polem e-mail.
- POST:
  1. `checkRateLimit('reset', $ip, $email)`.
  2. Vyhledej uživatele dle e-mailu (aktivní i neaktivní — nový uživatel musí heslo nastavit).
  3. Invaliduj staré tokeny: `UPDATE tel_password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL`.
  4. Vygeneruj token, vlož do `tel_password_resets` s `expires_at = UTC + 24h`.
  5. `sendPasswordResetEmail()`.
  6. **Vždy** zobraz stejnou zprávu: „Pokud e-mail existuje, byl odeslán odkaz." (prevence user enumeration).
  7. `recordRateFail()` se nevolá při neexistujícím e-mailu (aby nedocházelo k lockoutu průzkumem).

---

### 2.6 `reset-password.php`

- GET: ověří token (platný, neexpirovaný, nepoužitý) → zobraz formulář nové heslo + potvrzení.
- POST:
  1. Znovu ověř token (replay protection).
  2. Validuj heslo: min. 8 znaků.
  3. Porovnej potvrzení.
  4. `password_hash()`, UPDATE `tel_users.password_hash`.
  5. UPDATE `tel_password_resets.used_at = NOW()`.
  6. Redirect na login s flash: „Heslo bylo nastaveno. Přihlaste se."
- Neplatný/expirovaný token → zobraz chybu a odkaz na forgot-password.

---

## Fáze 3 – API: Požadavky

**Cíl:** Kompletní CRUD pro `tel_requests` se všemi byznys pravidly, audit logem a DB transakcemi.

### 3.1 `api/requests.php`

Autentizace na začátku: `startSecureSession(); requireLogin(); checkSessionTimeout(); touchSession();`  
Routing přes `$_GET['action']` a `$_SERVER['REQUEST_METHOD']`.

#### GET `?action=list`

Parametry: `status` (filtr), `sort` (`asc`/`desc` dle `created_at`), `search` (SPZ / jméno / telefon).

Dotaz (JOINy):
```sql
SELECT r.*, 
       u1.name AS created_by_name,
       u2.name AS assigned_to_name
FROM tel_requests r
LEFT JOIN tel_users u1 ON r.created_by = u1.id
LEFT JOIN tel_users u2 ON r.assigned_to_id = u2.id
WHERE r.deleted_at IS NULL
  AND r.status != 'resolved'  -- výchozí; status filtr přepíše
  [AND r.status = :status]
  [AND (r.spz LIKE :search OR r.client_name LIKE :search OR r.client_phone LIKE :search)]
ORDER BY r.created_at [ASC|DESC]
```

Vrátí pole objektů + `age_minutes` (vypočteno PHP).  
Časy konvertovány z UTC na `Europe/Prague` ve výstupu.

#### GET `?action=get&id=X`

Jednotlivý požadavek + pole `history` (posledních 20 záznamů z `tel_request_history`).

#### POST `?action=create`

Body (JSON): `spz`, `client_name`, `client_phone`, `client_email`, `request_text`.

1. Validace povinných polí.
2. `normalizeSpz()` pro uložení SPZ.
3. `nowUtc()` pro `created_at` a `updated_at`.
4. **Transakce:**
   - INSERT do `tel_requests`.
   - `logAudit(..., 'created')`.
5. Vrátí vytvořený záznam.

#### POST `?action=update`

Body (JSON): `id`, `expected_updated_at`, `action_type` + payload dle akce.

**Race condition check:**
```php
$current = getDB()->prepare('SELECT updated_at, status, assigned_to_id FROM tel_requests WHERE id = ?');
// pokud $current['updated_at'] !== $body['expected_updated_at'] → jsonErr(409, 'conflict', ['updated_by' => ...])
```

**Podporované `action_type`:**

| action_type     | Popis                                      | Povinná pole v body         | Status přechod             |
|-----------------|--------------------------------------------|-----------------------------|----------------------------|
| `assign`        | Převzetí nového ticketu                    | —                           | `new` → `in_progress`      |
| `takeover`      | Přebrání od jiného technika                | `takeover_reason` (opt.)    | `in_progress` → `in_progress` |
| `set_pending`   | Označit jako čekající                      | `pending_reason`            | `in_progress` → `pending`  |
| `resume`        | Pokračovat v řešení                        | —                           | `pending` → `in_progress`  |
| `resolve`       | Uzavřít jako vyřízené                      | `technician_note`           | `in_progress` → `resolved` |
| `reopen`        | Znovuotevřít                               | `reopen_reason`             | `resolved` → `reopened`    |
| `edit_field`    | Editace pole (SPZ, jméno, tel., e-mail)    | `field`, `value`            | beze změny stavu           |
| `soft_delete`   | Soft delete (jen admin)                    | —                           | → `deleted_at` = now       |

Každý `action_type` má validaci oprávnění (role, stav ticketu, ownership).  
Každý `action_type` musí být zabalen v DB transakci s `logAudit()`.

---

## Fáze 4 – API: Nastavení a statistiky

### 4.1 `api/settings.php`

- GET: Vrátí všechna nastavení jako JSON objekt (pro JS config na dashboardu).  
  Nevyžaduje admin — čtení je povoleno všem přihlášeným.

### 4.2 `api/stats.php`

Vyžaduje roli admin.

**GET `?view=by_technician&from=YYYY-MM-DD&to=YYYY-MM-DD`:**
```sql
SELECT u.name, 
       COUNT(*) AS total_resolved,
       ROUND(AVG(TIMESTAMPDIFF(MINUTE, r.created_at, r.resolved_at))) AS avg_minutes,
       COUNT(CASE WHEN r.status = 'reopened' THEN 1 END) AS reopened_count
FROM tel_requests r
JOIN tel_users u ON r.assigned_to_id = u.id
WHERE r.status = 'resolved'
  AND r.deleted_at IS NULL
  AND r.resolved_at BETWEEN :from AND :to
GROUP BY u.id, u.name
ORDER BY total_resolved DESC
```

**GET `?view=by_age&from=...&to=...`:**  
Agregace podle minutového stáří při uzavření do skupin dle aktuálních prahů z nastavení.

---

## Fáze 5 – Dashboard: HTML + CSS

### 5.1 `dashboard.php`

Struktura PHP stránky:
1. Auth check, načtení nastavení.
2. Výstup meta tagu s CSRF tokenem.
3. Výstup `<script>` bloku s konfigurací:

```php
<script>
const APP = {
    apiBase:          '<?= APP_URL ?>/api',
    csrfToken:        '<?= h($_SESSION['csrf_token']) ?>',
    refreshInterval:  <?= (int)getSetting('refresh_interval', 30) ?>,
    sessionTimeout:   <?= (int)getSetting('session_timeout', 500) ?>,
    colorThresholds:  <?= json_encode([
        (int)getSetting('color_level_1', 15),
        (int)getSetting('color_level_2', 30),
        (int)getSetting('color_level_3', 60),
        (int)getSetting('color_level_4', 120),
    ]) ?>,
    currentUser: {
        id:    <?= currentUserId() ?>,
        name:  '<?= h(currentUserName()) ?>',
        role:  '<?= h(currentUserRole()) ?>',
        isAdmin: <?= isAdmin() ? 'true' : 'false' ?>
    }
};
</script>
```

4. Bootstrap 5 CDN + Bootstrap Icons CDN.
5. Navigační lišta: logo, jméno uživatele, odkaz Admin (jen admin role), Odhlásit.
6. Záložky: **Nový požadavek** | **Požadavky**.
7. Tab „Nový požadavek": HTML formulář (SPZ, Jméno, Telefon, E-mail, Požadavek) + tlačítko Odeslat.
8. Tab „Požadavky":
   - Toolbar: rozbalovací menu intervalu, tlačítko řazení (↑↓), filtry (Vše/Nové/Převzaté/Čekající/Znovuotevřené/Jen moje), vyhledávací pole, tlačítko Obnovit, odpočet + progress bar.
   - Kontejner `<div id="requestList">` — sem JS renderuje karty.
9. Modální okno Bootstrap (prázdné, plnění přes JS).
10. Session warning banner (skrytý, aktivuje JS).
11. `<script src="assets/js/app.js">`.

---

### 5.2 `assets/css/style.css`

Klíčové sekce:

```
1. Root proměnné: --color-primary (#1e3a5f), --color-amber (#e8a000), --color-level-{1-5}
2. Reset / base
3. Navbar: tmavě modrá, logo bold
4. Login stránka: centrovaná karta, 400px, stín
5. Záložky (nav-tabs): zvýraznění aktivní
6. Toolbar požadavků: flex, wrap, gap
7. Progress bar odpočtu (3px, plynulá animace)
8. Karta požadavku (.req-card):
   - Levý pruh 5px dle úrovně (.level-1 až .level-5)
   - Level 5: pulzující border animace (@keyframes pulse)
   - Hover: translateY(-2px), stín
   - Badge SPZ: monospace, tmavě modrá
   - Badge stáří: šedá
   - Badge „Řeší": zelená
   - Badge „Znovuotevřeno": oranžová s ↩
   - Zvýraznění nových (.req-card-new): flash animace 5s
9. Modal: max-width 700px, history accordion
10. Admin: tabulky, formuláře
11. Responsivita: breakpoint 768px (karty na celou šířku, toolbar se zalamuje)
```

---

## Fáze 6 – Dashboard: JavaScript

### 6.1 `assets/js/app.js`

**Moduly (logické celky v jednom souboru):**

#### A. Inicializace
```javascript
document.addEventListener('DOMContentLoaded', () => {
    initTabs();
    initNewRequestForm();
    loadPreferencesFromStorage();
    loadRequests();
    startRefreshTimer();
    initSessionWatcher();
    requestBrowserNotificationPermission();
});
```

#### B. LocalStorage preferences
- `AB_TEL_REFRESH` — interval v sekundách
- `AB_TEL_SORT` — `'asc'` / `'desc'`
- `AB_TEL_FILTER` — `'all'`, `'new'`, `'in_progress'`, `'pending'`, `'reopened'`, `'mine'`
- `AB_TEL_SOUND` — `'0'` / `'1'`

Funkce: `loadPreferencesFromStorage()`, `savePreference(key, val)`.

#### C. Auto-refresh
```javascript
let refreshCountdown = APP.refreshInterval;
let refreshTimer = null;

function startRefreshTimer() { ... }  // setInterval 1s, při 0 volá loadRequests()
function resetTimer() { ... }         // countdown = interval, nezastavuje timer
function stopTimer() { ... }          // clearInterval
```

Při změně intervalu v dropdownu: `savePreference('AB_TEL_REFRESH', val); resetTimer();`

#### D. Načtení a zobrazení požadavků
```javascript
async function loadRequests() {
    const params = buildQueryParams();  // filter, sort, search
    const data = await apiGet('/requests.php?action=list&' + params);
    detectNewRequests(data);            // porovnání s předchozím stavem
    renderRequestList(data);
    updateTabTitle(data.length);
}

function renderRequestList(requests) { ... }
function createRequestCard(req) {
    // vrátí HTML string karty
    // ageLevel = getAgeLevel(req.created_at)
    // třídy: req-card, level-X, (req-card-new pro nové od posledního loadu)
}
function getAgeLevel(createdAtIso) { ... }  // porovná s APP.colorThresholds
function formatAge(createdAtIso) { ... }    // „47 min", „2 hod 5 min"
```

#### E. Notifikace nových ticketů
```javascript
let knownRequestIds = new Set();

function detectNewRequests(requests) {
    const newOnes = requests.filter(r => !knownRequestIds.has(r.id));
    if (newOnes.length > 0 && knownRequestIds.size > 0) {
        // flash CSS třída na nové karty
        // zvuk (pokud povolen)
        // Browser Notification (pokud document skrytý)
    }
    knownRequestIds = new Set(requests.map(r => r.id));
}

function updateTabTitle(count) {
    document.title = count > 0 ? `(${count}) ${APP_NAME}` : APP_NAME;
}
```

#### F. Modální okno
```javascript
async function openRequestModal(requestId) {
    const req = await apiGet(`/requests.php?action=get&id=${requestId}`);
    renderModal(req);
    // uložit req.updated_at jako expected_updated_at pro race check
    modal.show();
}

function renderModal(req) {
    // detail klienta (SPZ, jméno, tel., e-mail)
    // text požadavku
    // badge stavu + věk + čas vytvoření
    // badge „Řeší: [jméno]" nebo „Nepřevzato"
    // tlačítka dle stavu a oprávnění (viz tabulka v PRD 3.7)
    // história (accordion)
    // draft z sessionStorage (pokud existuje)
}
```

#### G. Akce z modálu (odeslání formuláře)
```javascript
async function submitAction(actionType, payload) {
    const body = {
        id: currentRequestId,
        expected_updated_at: expectedUpdatedAt,
        action_type: actionType,
        ...payload
    };
    const result = await apiPost('/requests.php?action=update', body);
    if (result.status === 409) {
        showConflictWarning(result.error);
        return;
    }
    clearDraft();
    modal.hide();
    loadRequests();
}
```

#### H. Formulář nového požadavku
```javascript
function initNewRequestForm() {
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const data = Object.fromEntries(new FormData(form));
        await apiPost('/requests.php?action=create', data);
        form.reset();
        showSuccess('Požadavek byl přijat.');
        switchTab('pozadavky');
        loadRequests();
    });
}
```

#### I. Session keepalive a warning
```javascript
function initSessionWatcher() {
    // každých 60s zkontroluj čas poslední aktivity uložený v sessionStorage
    // 5 min před expirací → zobraz banner
    // polling (loadRequests) slouží jako keepalive (každý GET prodlužuje session)
}

function showSessionWarning() { ... }   // banner s tlačítkem „Prodloužit"
function extendSession() { ... }        // AJAX GET /api/settings.php → prodlouží session
```

#### J. Draft autosave
```javascript
function saveDraft(requestId, text) {
    sessionStorage.setItem(`AB_DRAFT_${requestId}`, text);
}
function loadDraft(requestId) {
    return sessionStorage.getItem(`AB_DRAFT_${requestId}`) || '';
}
function clearDraft(requestId) {
    sessionStorage.removeItem(`AB_DRAFT_${requestId}`);
}
// textarea `input` event → saveDraft()
```

#### K. Pomocné API funkce
```javascript
async function apiGet(path) {
    const res = await fetch(APP.apiBase + path, { credentials: 'same-origin' });
    return res.json();
}

async function apiPost(path, body) {
    const res = await fetch(APP.apiBase + path, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': APP.csrfToken
        },
        body: JSON.stringify(body)
    });
    return { status: res.status, ...(await res.json()) };
}
```

---

## Fáze 7 – Admin: Backend API

### 7.1 `admin/api/users.php`

Autentizace: `requireAdmin()`.

| action           | Metoda | Popis                                               |
|------------------|--------|-----------------------------------------------------|
| `list`           | GET    | Všichni uživatelé (id, name, email, role, is_active, last_login) |
| `create`         | POST   | Jméno, e-mail, role. Vytvoří uživatele (bez hesla). Odešle reset e-mail. |
| `toggle_active`  | POST   | `id` → přepne `is_active`. Nelze blokovat sebe sama. |

---

## Fáze 8 – Admin: Frontend

### 8.1 `admin/index.php`

Přesměrování na `admin/users.php`.

### 8.2 `admin/users.php`

- Tabulka uživatelů (načtena přes `admin/api/users.php?action=list` při page load nebo ajaxem).
- Formulář „Nový uživatel" (inline nebo modal): jméno, e-mail, role.
- Tlačítko Blokovat/Odblokovat u každého řádku.
- Flash zprávy o úspěchu / chybě.

### 8.3 `admin/settings.php`

- Formulář se všemi nastaveními (číselné inputy s validací).
- POST → UPDATE `tel_settings` přes `setSetting()`.
- Redirect na sebe sama s flash „Nastavení uloženo."

### 8.4 `admin/stats.php`

- Filtry: datumové rozmezí (výchozí: aktuální měsíc).
- Tabulka „Podle techniků" načtena z `api/stats.php?view=by_technician`.
- Tabulka „Podle stáří" načtena z `api/stats.php?view=by_age`.
- Tabulka „Znovuotevřené."

### 8.5 `assets/js/admin.js`

- Funkce pro AJAX volání admin API.
- Renderování tabulek.
- Confirm dialogy (blokování, soft-delete).
- Inicializace formulářů.

---

## Fáze 9 – Integrace a hardening

### Checklist

- [ ] Rate limiting: otestovat lockout (5× špatné heslo z různých IP, 3× reset z e-mailu).
- [ ] Exponential backoff: ověřit, že 6. pokus prodlouží lockout 2×.
- [ ] CSRF: otestovat odmítnutí POST bez hlavičky (curl bez tokenu → 403).
- [ ] Race condition: otevřít tentýž ticket ve dvou záložkách, uložit v obou → ověřit 409 v druhé.
- [ ] Soft delete: ověřit, že smazaný ticket se nezobrazuje v seznamu ani ve statistikách.
- [ ] Session timeout: nastavit v adminu na 1 min, čekat → ověřit banner a odhlášení.
- [ ] Bezpečnostní hlavičky: ověřit `curl -I` nebo DevTools → Network → Headers.
- [ ] `logs/` nedostupné přes HTTP (URL tel.auto-borek.cz/logs/app.log → 403).
- [ ] `includes/` nedostupné přes HTTP → 403.
- [ ] `install.php` po vytvoření `install.lock` odmítne spustit se.
- [ ] Browser Notification: funguje při přijetí nového ticketu na pozadí.
- [ ] Zvuk: vypnutý/zapnutý dle localStorage.
- [ ] Draft: rozepsat poznámku, nechat expirovat session, přihlásit se → draft stále přítomen.
- [ ] Timezone: created_at uložen v UTC, zobrazen v Europe/Prague.
- [ ] SPZ normalizace: „1AB 12-34" uloženo jako „1AB1234" (ověřit v DB).

---

## Fáze 10 – Dokumentace

### 10.1 `docs/instalace.md`

Postup instalace krok za krokem:
1. Nahrání souborů na hosting (FTP/SFTP).
2. Vytvoření MySQL databáze a uživatele.
3. Úprava `includes/config.php`.
4. Nastavení subdomény a document root.
5. Spuštění `install.php`, zadání admin účtu.
6. Ověření vytvoření `install.lock`.
7. Přihlášení na `/login.php`.
8. Smazání / přejmenování `install.php` (nebo ponechání — `install.lock` zajistí blokaci).

### 10.2 `docs/databaze.md`

Kompletní DDL všech tabulek, popis sloupců, výčet povolených hodnot pro `status`, indexy.

---

## Pořadí implementace (doporučené)

```
Fáze 1  →  Fáze 2  →  Fáze 3  →  Fáze 4
                           ↓           ↓
                        Fáze 5  →  Fáze 6
                           ↓
                        Fáze 7  →  Fáze 8
                                      ↓
                                   Fáze 9  →  Fáze 10
```

**Milníky pro průběžné testování:**
- Po Fázi 2: lze se přihlásit, odhlásit, nastavit heslo.
- Po Fázi 3+4: API funguje — testovat `curl` nebo Postman.
- Po Fázi 6: plná funkčnost dashboardu.
- Po Fázi 8: plná admin sekce.
- Po Fázi 9: systém připraven k produkčnímu nasazení.
