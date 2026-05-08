# Instalační příručka – AutoBorek Tel

**Verze:** 1.0  
**Datum:** 2026-05-08

---

## Předpoklady

| Požadavek     | Minimum       |
|---------------|---------------|
| PHP           | 8.0+          |
| MySQL         | 5.7+ nebo 8.0 |
| PHP extenze   | PDO, pdo_mysql |
| SMTP          | Dostupné přes `mail()` nebo hosting SMTP |

---

## Postup instalace

### 1. Nahrání souborů

Nahrajte celý obsah složky `telefon/` na hosting do webrootu subdomény `tel.auto-borek.cz`.

Doporučené metody: FTP/SFTP (např. FileZilla), nebo Git pull na serveru.

Výsledná struktura na serveru:

```
/var/www/tel.auto-borek.cz/   ← nebo cesta dle hostingu
├── index.php
├── login.php
├── dashboard.php
├── install.php
├── .htaccess
├── includes/
├── api/
├── admin/
├── assets/
└── logs/
```

---

### 2. Vytvoření MySQL databáze

V phpMyAdmin nebo přes SSH:

```sql
CREATE DATABASE autoborek_tel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'autoborek_tel'@'localhost' IDENTIFIED BY 'SILNE_HESLO';
GRANT ALL PRIVILEGES ON autoborek_tel.* TO 'autoborek_tel'@'localhost';
FLUSH PRIVILEGES;
```

---

### 3. Konfigurace `includes/config.php`

Otevřete soubor `includes/config.php` a upravte konstanty:

```php
define('DB_HOST',  'localhost');
define('DB_NAME',  'autoborek_tel');
define('DB_USER',  'autoborek_tel');
define('DB_PASS',  'SILNE_HESLO');       // ← doplňte skutečné heslo

define('APP_URL',  'https://tel.auto-borek.cz');
define('APP_NAME', 'AutoBorek | Tel');

define('MAIL_FROM',      'tel@auto-borek.cz');
define('MAIL_FROM_NAME', 'AutoBorek Tel');
```

---

### 4. Nastavení subdomény a document root

V administraci hostingu nastavte:

- **Subdoména:** `tel.auto-borek.cz`
- **Document root:** složka, kam jste nahráli soubory (webroot projektu)

Ověřte, že Apache/Nginx zpracovává soubor `.htaccess` (na Apache je potřeba `AllowOverride All`).

---

### 5. Spuštění instalačního skriptu

Otevřete v prohlížeči:

```
https://tel.auto-borek.cz/install.php
```

Vyplňte:
- **Jméno** admin uživatele
- **E-mail** admin uživatele
- **Heslo** (min. 8 znaků, potvrdit)

Klikněte **Nainstalovat**.

Po úspěšné instalaci se vytvoří soubor `install.lock`. Opakované spuštění `install.php` je poté zablokováno.

---

### 6. Ověření instalace

Po instalaci by se mělo zobrazit potvrzení s odkazem na přihlášení.

Přejděte na:
```
https://tel.auto-borek.cz/login.php
```

Přihlaste se zadanými údaji admin účtu.

---

### 7. Přidání dalších uživatelů

1. Přihlaste se jako admin.
2. Přejděte do sekce **Admin → Uživatelé**.
3. Klikněte **Nový uživatel**, zadejte jméno, e-mail a roli.
4. Systém odešle uživateli e-mail s odkazem pro nastavení hesla (platný 24 hodin).
5. Nový uživatel si přes odkaz nastaví vlastní heslo.

---

### 8. Bezpečnostní doporučení po instalaci

- Soubor `install.php` je blokován přes `install.lock`. Pro extra jistotu jej můžete přejmenovat nebo smazat.
- Ověřte, že složky `includes/` a `logs/` jsou nepřístupné přes HTTP:
  - `https://tel.auto-borek.cz/includes/config.php` → musí vrátit HTTP 403
  - `https://tel.auto-borek.cz/logs/app.log` → musí vrátit HTTP 403
- Zkontrolujte bezpečnostní hlavičky: `curl -I https://tel.auto-borek.cz` a ověřte přítomnost `X-Frame-Options`, `X-Content-Type-Options`.

---

### 9. Nastavení systému

Po přihlášení jako admin přejděte na **Admin → Nastavení** a zkontrolujte:

| Parametr | Výchozí | Popis |
|---|---|---|
| Interval aktualizace | 30 s | Jak často se automaticky obnoví seznam požadavků |
| Práh barvy 1→2 | 15 min | Kdy se karta změní z zelené na žlutou |
| Práh barvy 2→3 | 30 min | Kdy žlutá → oranžová |
| Práh barvy 3→4 | 60 min | Kdy oranžová → červená |
| Práh barvy 4→5 | 120 min | Kdy červená → tmavě červená (pulsující) |
| Session timeout | 500 min | Nečinnost před automatickým odhlášením |

---

## Řešení problémů

| Problém | Řešení |
|---|---|
| Bílá stránka po instalaci | Zkontrolujte PHP error log, zapněte `display_errors` dočasně |
| Nelze se přihlásit | Ověřte DB přihlašovací údaje v `config.php` |
| E-mail se neodesílá | Zkontrolujte SMTP konfiguraci hostingu; otestujte `mail()` samostatným skriptem |
| HTTP 403 na celou aplikaci | Ověřte `.htaccess` a `AllowOverride` v konfiguraci Apache |
| Chyba PDO při instalaci | Ověřte existenci DB a uživatelských oprávnění |
