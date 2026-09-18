# PRD – Evidenční systém telefonických požadavků AutoBorek

**Verze:** 1.7  
**Datum:** 2026-09-18  
**Adresa aplikace:** https://tel.auto-borek.cz  
**Administrace:** https://tel.auto-borek.cz/admin/  
**Technologie:** PHP 8+, MySQL, JavaScript, Bootstrap 5  

---

## Changelog

| Verze | Datum      | Změna                                                                                                   |
|-------|------------|---------------------------------------------------------------------------------------------------------|
| 1.0   | 2026-05-08 | Prvotní verze                                                                                           |
| 1.1   | 2026-05-08 | Ownership ticketu, stav `in_progress`, audit log, pravidla editace, ochrana souběhu, vyhledávání, rate limiting, session timeout, GDPR, nefunkční požadavky |
| 1.2   | 2026-05-08 | Lifecycle po resolved (stav `reopened`), soft delete, transakční pravidla, indexy, timezone UTC/Prague, rate limiting IP+email, bezpečnostní hlavičky, notifikace nových ticketů, filtr „Jen moje", potvrzení přebrání, omezení editace po uzavření, session keepalive + autosave, retenční politika audit logu |
| 1.3   | 2026-06-25 | Lookup SPZ → model + rok + VIN implementován (přesun z v2.0); S3 synchronizace vozidel (SSIS → S3 → cron); dark mode, klávesové zkratky, cache-busting; admin: smazané záznamy + obnova; DB zálohy na S3 (`crony/backup-db.php`); nová admin stránka Evidence vozidel |
| 1.4   | 2026-06-25 | Značka vozidla z VIN (WMI lookup); barevné brand badge pro Renault/Dacia/Nissan; u vyřízených požadavků zobrazení doby řešení místo stáří |
| 1.5   | 2026-06-26 | Barevné rozlišení vyřízených požadavků podle doby řešení (ne stáří); lookup vozidla při zadávání nového požadavku (blur na SPZ); statistiky podle doby vyřízení doplněny o procentuální podíl |
| 1.6   | 2026-07-03 | Editace jména a e-mailu uživatele v admin správě uživatelů |
| 1.7   | 2026-09-18 | Plánovač – objednávky do servisu: tabulka `tel_service_orders` plněná ze dvou S3 souborů (`planovac-objednano.csv`, `planovac-prijem.csv`); termín objednání + jméno zákazníka na kartě požadavku a v detailu; nová admin stránka Objednávky; jeden cron endpoint pro vozidla i objednávky; sjednocená S3 sync logika; nové rozložení karty (2 sloupce, poznámka technika vpravo nahoře) |

---

## 1. Cíl produktu

Webová aplikace umožňující recepci autoservisu evidovat příchozí telefonické požadavky klientů a technikům je operativně zpracovávat. Systém zajišťuje přehled o stavu požadavků v reálném čase, sleduje dobu jejich vyřízení a uchovává auditní stopu všech změn.

---

## 2. Uživatelské role

| Role    | Popis                                                                                              |
|---------|----------------------------------------------------------------------------------------------------|
| `admin` | Plný přístup: správa uživatelů, nastavení systému, statistiky, anonymizace dat, soft delete záznamů |
| `user`  | Vytváří a zpracovává požadavky; nemůže mazat ani anonymizovat                                      |

### 2.1 Přihlášení a správa hesel

- Přihlášení e-mailem a heslem.
- Nového uživatele vytváří admin (jméno, e-mail, role). Účet je vytvořen **bez hesla**.
- Nový uživatel si heslo nastaví přes **Zapomenuté heslo** — odkaz na e-mail, platí **24 hodin**.
- Vygenerování nového reset-tokenu **invaliduje** všechny předchozí tokeny daného uživatele.
- Admin může uživatele **zablokovat** (znemožní přihlášení); odblokování je reverzibilní.
- Blokování nemaže uživatele — historická data zůstávají.

---

## 3. Přehled funkcí

### 3.1 Příjem požadavku (recepce)

Formulář „Nový požadavek":

| Pole      | Povinné | Validace                                              |
|-----------|---------|-------------------------------------------------------|
| SPZ       | Ano     | Ukládá se uppercase, normalizovaně (bez mezer/pomlček) |
| Jméno     | Ano     | Max. 100 znaků                                        |
| Telefon   | Ne      | Validace formátu; normalizace na +420… kde je možné   |
| E-mail    | Ne      | Validace RFC formátu                                  |
| Požadavek | Ano     | Textarea, max. 2 000 znaků                            |

Po odeslání: stav **Nový**, zápis do audit logu, zobrazení potvrzení.

### 3.2 Stavy požadavku a jejich lifecycle

| Stav          | Popis                                                                   | Kdo nastavuje                  |
|---------------|-------------------------------------------------------------------------|--------------------------------|
| `new`         | Nový, nepřevzatý                                                        | Systém (při vytvoření)         |
| `in_progress` | Technik převzal a aktivně řeší                                          | Technik (tlačítko „Převzít")   |
| `pending`     | Čeká na díl, klienta, schválení; vyžaduje `pending_reason`              | Technik                        |
| `resolved`    | Vyřízeno; skryto z výchozího zobrazení                                  | Technik                        |
| `reopened`    | Bylo vyřízeno, ale klient znovu volal nebo je třeba dořešit             | Jakýkoli `user` nebo `admin`   |

**Povolené přechody:**

```
new ──► in_progress ──► resolved ──► reopened ──► in_progress
             │                │
             ▼                └──► (přímo, výjimečně admin)
           pending
             │
             └──► in_progress
```

- Z `resolved` lze přejít **pouze** do `reopened` (ne zpět do `new`/`pending`).
- Z `reopened` ticket vstoupí zpět do fronty — viditelný v seznamu s barevným odlišením.
- Přechod do `reopened` vyžaduje vyplnění **důvodu znovuotevření** (`reopen_reason`).
- Statistiky sledují každý cyklus `created_at → resolved_at` odděleně.

### 3.3 Seznam požadavků (technici)

- Výchozí zobrazení: aktivní požadavky (stavy `new`, `in_progress`, `pending`, `reopened`).
- Vyřízené požadavky jsou skryté; zobrazí se filtrem.
- **Automatická aktualizace** každých N sekund — výchozí z administrace, technik ji může změnit v rozbalovacím menu. Nastavení se ukládá do **localStorage** a přetrvává do doby, než ho uživatel sám změní.
- Vizuální odpočet do příští aktualizace + tlačítko „Obnovit nyní".
- **Filtry:** Vše / Nové / Převzaté / Čekající / Znovuotevřené / **Jen moje** (assigned_to = přihlášený uživatel).
- **Řazení:** výchozí od nejstarších, přepnutí na nejnovější první. Nastavení se ukládá do **localStorage**.
- **Vyhledávání:** live-filter nad aktuálně načtenými aktivními daty podle SPZ, jména nebo telefonu klienta.
  - Poznámka: prohledává pouze aktivní požadavky aktuálně zobrazené v seznamu. Hledání ve vyřízených požadavcích (server-side) je plánováno pro v. 2.0.

**Rozložení karty požadavku** (dvousloupcová mřížka `.card-grid`, na mobilu jeden sloupec):

| Řádek | Levý sloupec                                         | Pravý sloupec                                              |
|-------|------------------------------------------------------|------------------------------------------------------------|
| 1     | ikona úrovně · SPZ · jméno · telefon · stavové badge | 🔧 poznámka technika (zkrácená, tooltip) · stáří · datum   |
| 2     | brand badge · model · rok · VIN                      | —                                                          |
| 3     | text požadavku                                       | 📅 Plánovač: termín (zákazník) — viz 3.13                  |

Pravý sloupec je pro řádky 1 a 3 týž grid-sloupec, takže poznámka technika a Plánovač mají vždy stejný levý okraj.

### 3.4 Notifikace nových požadavků

Po každém automatickém obnovení systém vizuálně upozorní na nové a znovuotevřené tickety:

- Nové tickety jsou **zvýrazněny** po dobu 5 sekund (flash animace).
- Záložka prohlížeče zobrazuje počet aktivních ticketů v titulku: `(3) AutoBorek | Tel`.
- **Zvuková notifikace** (volitelná, zapínatelná v localStorage — výchozí: vypnuto).
- **Browser Notification** — systém si vyžádá oprávnění; upozorní při přijetí nového ticketu, pokud je záložka na pozadí.

### 3.5 Lookup SPZ → vozidlo a značka

Po načtení přehledu i po otevření detailu se k požadavku automaticky doplní informace o vozidle z databáze `tel_vehicles`:

- **Model**, **rok výroby** a **VIN** se zobrazí na kartě i v modálním okně.
- Data se doplňují pomocí `LEFT JOIN tel_vehicles ON spz_normalized = r.spz` — pokud SPZ v databázi není, karta se zobrazí normálně bez dopadu.
- Databáze vozidel se plní synchronizací ze S3 (viz sekce 4.7).

**Lookup při zadávání nového požadavku:**

- Po vyplnění SPZ a přechodu na pole Jméno klienta (blur) aplikace asynchronně volá `api/requests.php?action=vehicle_lookup&spz=…`.
- **Nalezeno:** pod polem SPZ se zobrazí zelený box s brand badge, modelem, rokem a VIN.
- **Nenalezeno:** zobrazí se výrazný žlutý warning box „Vozidlo s touto SPZ není v databázi."
- Hint se při otevření formuláře vždy vymaže.

**Značka z VIN (WMI lookup):**

- PHP funkce `vinToBrand()` odvozuje značku z prvních 3 znaků VIN (WMI — World Manufacturer Identifier).
- Pokrývá ~40 kódů pro běžné evropské a japonské značky.
- Výsledek je dostupný jako pole `vehicle_brand` v API odpovědi.

**Brand badge:**

Značka se zobrazuje jako barevný badge před údaji o vozidle. Badge nahrazuje generickou ikonku auta — pokud je značka neznámá, ikonka `bi-car-front` zůstává.

| Značka  | Barva badge       | Prefix |
|---------|-------------------|--------|
| Renault | Zlatá (`#f5c518`) | ◆      |
| Dacia   | Tmavě modrá       | —      |
| Nissan  | Červená           | ●      |
| Ostatní | Šedá              | —      |

### 3.6 Barevné rozlišení a přístupnost (5 úrovní)

Prahové hodnoty (v minutách) konfigurovatelné v administraci:

| Úroveň | Barva levého pruhu | Ikona                  | Výchozí rozsah    |
|--------|--------------------|------------------------|--------------------|
| 1      | Zelená             | ●                      | 0 – t1 minut       |
| 2      | Žlutá              | ◆                      | t1 – t2 minut      |
| 3      | Oranžová           | ▲                      | t2 – t3 minut      |
| 4      | Červená            | ✖                      | t3 – t4 minut      |
| 5      | Tmavě červená      | ⚠ (pulzující animace)  | t4+ minut          |

Výchozí prahy: **15 / 30 / 60 / 120** minut.

Karta zobrazuje i **textový badge s věkem** (např. „47 min") — informace není závislá jen na barvě.  
Stav `reopened` je na kartě označen ikonou ↩ a textem „Znovuotevřeno".

U požadavků ve stavu `resolved` se místo aktuálního stáří zobrazuje **doba řešení** (od `created_at` do `resolved_at`) s ikonou ✓. V detailu modálu se řádek „Stáří" přejmenuje na „Vyřízeno za".

**Barevné rozlišení vyřízených požadavků:** úroveň barvy se odvozuje od `resolution_minutes` (doby řešení), nikoli od aktuálního stáří záznamu. Zelená = vyřízeno rychle, tmavě červená = vyřízeno pomalu.

### 3.7 Převzetí a ownership požadavku

- Badge **„Řeší: [jméno]"** na kartě pro stav `in_progress`.
- Technik klikne „Převzít" → stav `in_progress`, zaznamená se `assigned_to_id` a `assigned_at`.
- **Přebrání od jiného technika:** zobrazí se potvrzovací dialog s volitelným polem „Důvod přebrání". Akce se loguje; původnímu technikovi se zobrazí informační hlášení při příštím načtení jeho ticketů.

### 3.8 Zpracování požadavku (modální okno)

Modální okno:

- Plný detail klienta a požadavku + stáří + čas přijetí.
- Badge: kdo ticket drží.
- Pole **Poznámka k řešení** (povinné při uzavírání).
- Pole **Důvod čekání** (povinné při nastavení `pending`).
- Pole **Důvod znovuotevření** (povinné při přechodu do `reopened`).
- Tlačítka dle aktuálního stavu:

| Stav ticketu  | Dostupná tlačítka                                         |
|---------------|-----------------------------------------------------------|
| `new`         | Převzít                                                   |
| `in_progress` | Uzavřít (Vyřízeno), Označit jako čekající, (Přebrat — jen pokud cizí) |
| `pending`     | Převzít / Pokračovat v řešení → `in_progress`             |
| `resolved`    | Znovuotevřít → `reopened`                                 |
| `reopened`    | Převzít                                                   |

### 3.9 Editace požadavku

| Pole                   | `user` (před resolved)          | `user` (po resolved/reopened)   | `admin` kdykoli |
|------------------------|---------------------------------|---------------------------------|-----------------|
| SPZ, Jméno, Tel., E-mail | Ano (s audit logem)            | Ne                              | Ano             |
| Text požadavku         | Jen před `in_progress`          | Ne                              | Ano             |
| Poznámka k řešení      | Přiřazený technik               | Ne                              | Ano             |
| Důvod čekání           | Přiřazený technik (při pending) | Ne                              | Ano             |

Každá editace se loguje do `tel_request_history`.

### 3.10 Ochrana souběžných úprav

- Při otevření modálu se načte `updated_at` požadavku.
- Při ukládání klient odešle `expected_updated_at`; server porovná s hodnotou v DB.
- Nesoulad → HTTP **409**: *„Požadavek byl mezitím upraven uživatelem [jméno]. Klikněte pro obnovení dat."*

### 3.11 Session keepalive a autosave

- Každý API GET požadavek (polling) prodlužuje session — funguje jako **silent keepalive**.
- Pokud je session 5 minut před vypršením (a uživatel je nečinný), zobrazí se **banner**: *„Vaše session vyprší za 5 minut. Klikněte pro prodloužení."*
- Při odpočtu session systém automaticky **uloží draft** otevřeného modálu do `sessionStorage`, aby uživatel po přihlášení nepřišel o rozepsaný text.

### 3.12 Sledování doby vyřízení

- Systém ukládá `created_at` a `resolved_at` pro každý cyklus.
- SLA čas = od `created_at` do `resolved_at` (včetně doby ve stavu `pending`). Toto je záměrné — pending = klient čeká, čas běží.
- Znovuotevřené tickety tvoří nový cyklus s novou `resolved_at` po opětovném uzavření.

### 3.13 Plánovač – objednávky do servisu na kartě požadavku

Když je vozidlo z požadavku objednané do servisu, technik to vidí přímo na kartě i v detailu, aniž by musel otevírat plánovač v DMS.

**Zdroj dat:** tabulka `tel_service_orders` (viz 4.9), plněná ze dvou CSV souborů plánovače na S3.

**Párování k požadavku:**
- primárně podle SPZ (`tel_requests.spz` = `tel_service_orders.spz_normalized`),
- záložně podle VIN z evidence vozidel (`tel_vehicles.vin` = `tel_service_orders.vin`) — pokrývá objednávky nových vozidel bez SPZ.

**Kdy se termín zobrazí:**
1. termín je **dnes nebo v budoucnu** (hranice = dnešní půlnoc v Europe/Prague; dnešní termín zůstává viditelný celý den i po svém čase),
2. u **vyřízeného** požadavku navíc **datum termínu ≥ datum vyřízení** (porovnává se pražský den, ne čas).

**Zobrazení:**
- Karta: `📅 Plánovač: 13.10.2026 11:00 (Jan Novák)` v pravém sloupci 3. řádku; více termínů oddělených ` · `.
- Detail (modal): řádek „Objednáno do servisu: …“ pod informací o vozidle.
- Stejné vozidlo se stejným termínem v obou zdrojových souborech se zobrazí jen jednou (přednost `objednano`); termín pouze ze souboru příjmu má příponu „– příjem“.

**Implementace:** `getUpcomingServiceOrders()` načte jedním dotazem všechny nadcházející objednávky pro SPZ/VIN z aktuální stránky seznamu, `matchServiceOrders()` je přiřadí k řádkům a deduplikuje; API vrací pole `service_orders[] = {scheduled_at_local, client_name, source}`.

---

## 4. Administrace (`/admin/`)

Přístupná pouze pro roli `admin`.

### 4.1 Správa uživatelů

- Seznam uživatelů (jméno, e-mail, role, stav, poslední přihlášení).
- Přidání nového uživatele → systém odešle e-mail s odkazem pro nastavení hesla.
- **Editace jména a e-mailové adresy** existujícího uživatele (tlačítko „Upravit"; modal s validací a kontrolou duplicity e-mailu).
- Blokování / odblokování uživatele.
- Uživatele **nelze smazat** ani soft-delete (zachování historických dat).

### 4.2 Nastavení systému

| Parametr                     | Popis                                                | Výchozí  |
|------------------------------|------------------------------------------------------|----------|
| Výchozí interval aktualizace | Sekund (výchozí pro nové relace)                     | 30 s     |
| Práh barvy úrovně 1          | Minuty do přechodu na úroveň 2                       | 15 min   |
| Práh barvy úrovně 2          | Minuty do přechodu na úroveň 3                       | 30 min   |
| Práh barvy úrovně 3          | Minuty do přechodu na úroveň 4                       | 60 min   |
| Práh barvy úrovně 4          | Minuty do přechodu na úroveň 5                       | 120 min  |
| Timeout nečinnosti session   | Minut nečinnosti do automatického odhlášení          | 500 min  |
| S3 Region / Bucket          | Amazon S3 pro synchronizaci vozidel, objednávek a zálohy DB | —        |
| Cesta k souboru vozidel      | Object key `export-spz.csv` (`s3_object_key`)         | —        |
| Cesta k souboru objednávek (objednáno) | Object key `planovac-objednano.csv` (`s3_orders_object_key`) | — |
| Cesta k souboru objednávek (příjem)    | Object key `planovac-prijem.csv` (`s3_prijem_object_key`)    | — |
| AWS Access Key ID / Secret   | Přihlašovací údaje S3 (chráněno e-mailovým kódem)   | —        |
| Klíč pro cron endpoint       | Tajný řetězec pro `api/sync-vehicles.php?key=…` (vozidla + objednávky) | — |
| Složka pro DB zálohy v S3    | Prefix objektu (např. `backups`)                     | backups  |
| Klíč pro cron endpoint zálohy | Tajný řetězec pro `crony/backup-db.php?key=…`      | —        |

### 4.3 Statistiky

- **Podle techniků:** počet vyřízených požadavků, průměrná celková doba vyřízení, počet přebrání.
- **Podle doby vyřízení:** počet v každém časovém pásmu + procentuální podíl z celku.
- **Znovuotevřené:** počet ticketů reopened a průměrná doba do opětovného uzavření.
- Filtr: rozsah datumů.
- Fyzické mazání ticketů je **zakázáno** i pro admina — statistiky musí zůstat konzistentní.

### 4.4 Soft delete záznamů

- Admin může ticket **skrýt** (soft delete: nastaví `deleted_at`).
- Soft-deleted tickety jsou skryty ze všech pohledů, ale data jsou zachována.
- Soft delete se loguje v `tel_request_history`.
- Fyzické smazání z DB je zakázáno (přístup přes aplikaci neumožněn).

### 4.5 GDPR — anonymizace dat

- Admin může spustit **anonymizaci** vyřízených požadavků starších než zvolený počet dnů.
- Anonymizace nahradí `client_name`, `client_phone`, `client_email` za `[anonymizováno]`.
- SPZ, text požadavku, řešení a statistická data zůstávají.
- Doporučená retenční lhůta: **2 roky** od `resolved_at`.
- Akce se loguje v `tel_request_history`.

### 4.6 Správa audit logu

- Admin vidí audit log k jednotlivým ticketům v detailu požadavku.
- Globální pohled na audit log (všechny akce) — s filtrem podle uživatele a datumu.
- Retenční politika logu: záznamy starší **3 let** lze hromadně smazat (i fyzicky, protože jde o logy, ne o byznys data).

### 4.7 Evidence vozidel (`/admin/import-vehicles.php`)

Správa databáze vozidel sloužící pro automatický lookup SPZ.

**Synchronizace ze S3:**
- Admin spustí synchronizaci ručně tlačítkem (stahuje vždy), nebo probíhá automaticky přes cron (`api/sync-vehicles.php?key=…`). Tentýž cron endpoint synchronizuje i objednávky do servisu (viz 4.9).
- Cron endpoint před stažením provede HEAD request (ETag) — pokud se soubor nezměnil, stahování přeskočí.
- Společná logika (HEAD → ETag → stažení → import → nastavení → protokol) je v `fetchAndImportS3Csv()`; obálky `syncVehiclesFromS3()` a `syncServiceOrdersFromS3()` používá cron i ruční tlačítka.
- Výsledky synchronizace: počet nově vložených, aktualizovaných a přeskočených řádků.
- Protokol posledních 25 synchronizací (čas, zdroj `ruční/cron`, výsledek, detail chyby).

**Import ze souboru:**
- Jednorázový import z `dataupload/export-spz.csv` uloženého přímo na serveru (přes FTP).
- Vstupní CSV: UTF-8 nebo Windows-1250, oddělovač `;`, záhlaví: `klic;spz;nazvoz;rokvyr;vin`.

**Formát CSV (SSIS export z DMS):**
- SSIS balíček exportuje tabulku vozidel z MSSQL na disk, Synology synchronizuje soubor na Amazon S3.
- VIN validace: pouze 17 znaků `[A-HJ-NPR-Z0-9]`; ostatní se uloží jako NULL.
- SPZ se normalizuje (uppercase, bez mezer/pomlček) při importu do `spz_normalized`.

**Zabezpečení S3 nastavení:**
- Nastavení AWS klíčů (společné pro vozidla, objednávky i zálohy) je ve výchozím stavu uzamčeno. Zde se nastavují i cesty k oběma souborům objednávek.
- Odemčení vyžaduje 6místný jednorázový kód zaslaný e-mailem (platnost 10 minut, max. 3 pokusy).
- Po uložení se sekce automaticky znovu zamkne.

**Stav databáze:**
- Zobrazuje počet vozidel v DB a datum poslední synchronizace ze S3.

### 4.8 DB zálohy na S3 (`crony/backup-db.php`)

Automatické zálohy databáze do Amazon S3.

- Cron endpoint `crony/backup-db.php?key=…` vygeneruje SQL dump celé DB, zkomprimuje ho (gzip, level 6) a nahraje na S3 jako `{prefix}/telefon-YYYY-MM-DD-HHmm.sql.gz`.
- Sdílí S3 přihlašovací údaje s evidencí vozidel; zálohovací prefix se konfiguruje zvlášť.
- Protokol posledních pokusů: čas, název souboru, velikost.
- Klíč pro cron endpoint se vygeneruje automaticky při prvním zobrazení stránky; lze resetovat.
- Cron URL se zobrazí v `admin/settings.php` k nastavení na webhostingu.

### 4.9 Objednávky do servisu (`/admin/orders.php`)

Správa tabulky `tel_service_orders`, která napájí Plánovač na kartě požadavku (3.13).

**Zdrojové soubory (Amazon S3, stejná složka jako `export-spz.csv`):**

| Soubor                     | `source`    | Popis                              |
|----------------------------|-------------|------------------------------------|
| `planovac-objednano.csv`   | `objednano` | Vozidla objednaná do servisu       |
| `planovac-prijem.csv`      | `prijem`    | Plán příjmu vozidel                |

Oba soubory mají stejnou strukturu: `datum_zac;spz;fabkod;vinkod;klient` (Windows-1250 nebo UTF-8, oddělovač `;`, hodnoty doplněné mezerami). Cca 70 % řádků je v obou souborech shodných.

**Import (`importServiceOrdersCsv()`):**
- **VIN = `fabkod` + `vinkod`** (3 + 14 znaků); validace `[A-HJ-NPR-Z0-9]{17}`, jinak NULL.
- `datum_zac` je lokální čas plánovače (Europe/Prague) → převádí se na UTC (`pragueToUtc()`).
- SPZ se normalizuje jako u vozidel; řádek bez SPZ i bez platného VIN se přeskočí.
- Soubor je **snímek** aktuálního stavu: import v jedné transakci smaže řádky svého `source` a vloží nové. Zrušené objednávky tak z tabulky zmizí, řádky druhého zdroje zůstávají.
- Ochrana proti cizímu souboru: hlavička musí začínat `datum_zac`, jinak se import odmítne a tabulka se nezmění.

**Synchronizace:**
- Cron `api/sync-vehicles.php?key=…` synchronizuje vozidla → objednáno → příjem; každý soubor má vlastní ETag (`s3_orders_last_etag`, `s3_prijem_last_etag`). Soubor bez nastavené cesty se přeskočí bez chyby. Odpověď: `{vehicles, orders: {objednano, prijem}}`; při chybě kterékoli části HTTP 500.
- Ruční tlačítko na stránce Objednávky synchronizuje oba soubory (ignoruje ETag).

**Stránka zobrazuje:**
- počet objednávek v DB (a z toho nadcházejících), poslední synchronizaci, cesty a datum změny obou souborů,
- protokol posledních 25 synchronizací (čas, ruční/cron, soubor, výsledek, načteno/přeskočeno, chyba),
- náhled nadcházejících objednávek od dneška (max. 500) seskupených po dnech: čas, SPZ, model z evidence vozidel, VIN, klient, zdroj.

**Migrace:** `_local/migrate-service-orders.sql` (tabulka + nastavení), poté `_local/migrate-service-orders-prijem.sql` (sloupec `source` + nastavení pro příjem).

---

## 5. Plánované funkce (výhled – v. 2.0)

### 5.1 Server-side vyhledávání

- Fulltext vyhledávání přes `resolved` tickety v DB.
- Stránkování výsledků.

### 5.2 Komunikace s klientem

- Odeslání **e-mailu** klientovi ze systému.
- Odeslání **SMS** klientovi.

### 5.3 Ostatní

- Rozšířené statistiky — export do CSV, grafy.

---

## 6. Technická architektura

### 6.1 Nasazení

- **Doména:** `tel.auto-borek.cz` (samostatná subdoména)
- **Webroot** = kořen projektu `telefon/`
- **Hosting:** sdílený webhosting, PHP 8+, MySQL 5.7+/8.0, SMTP

### 6.2 Adresářová struktura

```
telefon/                        ← webroot subdomény tel.auto-borek.cz
├── index.php
├── login.php
├── logout.php
├── forgot-password.php
├── reset-password.php
├── dashboard.php
├── .htaccess                   ← security headers, deny includes/, deny logs/
│
├── includes/                   ← přístup zakázán přes HTTP
│   ├── config.php
│   ├── db.php
│   ├── auth.php
│   └── functions.php
│
├── api/
│   ├── requests.php
│   ├── settings.php
│   ├── stats.php
│   ├── sms.php
│   └── sync-vehicles.php       ← cron endpoint (auth: ?key=…): S3 → tel_vehicles + tel_service_orders
│
├── admin/
│   ├── index.php
│   ├── users.php
│   ├── settings.php            ← systém + S3 + DB zálohy
│   ├── stats.php
│   ├── sms.php
│   ├── import-vehicles.php     ← evidence vozidel + S3 nastavení (společné) + protokol
│   ├── orders.php              ← objednávky do servisu: S3 sync, protokol, náhled
│   ├── .htaccess
│   └── api/
│       └── users.php
│
├── crony/                      ← přístup chráněn klíčem v URL
│   └── backup-db.php           ← SQL dump → gzip → S3
│
├── assets/
│   ├── css/style.css
│   └── js/
│       ├── app.js
│       └── admin.js
│
├── dataupload/                 ← pro jednorázový FTP upload CSV
│   └── export-spz.csv          ← vstupní soubor pro import vozidel
│
├── logs/                       ← přístup zakázán přes HTTP (.htaccess deny)
│   └── app.log
│
├── install.php                 ← self-disable přes install.lock
├── install.lock                ← vytvoří se po instalaci, blokuje reinstalaci
│
└── docs/
    ├── PRD.md
    ├── instalace.md
    └── databaze.md
```

### 6.3 Databázové tabulky (prefix `tel_`)

| Tabulka               | Popis                                                              |
|-----------------------|--------------------------------------------------------------------|
| `tel_users`           | Uživatelé systému                                                  |
| `tel_requests`        | Telefonické požadavky                                              |
| `tel_request_history` | Audit log každé změny požadavku                                    |
| `tel_settings`        | Konfigurace systému (key-value)                                    |
| `tel_password_resets` | Tokeny pro obnovu hesla                                            |
| `tel_rate_limits`     | Ochrana před brute-force                                           |
| `tel_vehicles`        | Evidence vozidel: SPZ → model, rok výroby, VIN                    |
| `tel_sms_queue`       | Fronta odchozích SMS                                               |
| `tel_service_orders`  | Objednávky do servisu (snímek plánovače ze dvou S3 souborů)        |

#### tel_vehicles — pole

| Sloupec         | Typ              | Poznámka                                              |
|-----------------|------------------|-------------------------------------------------------|
| `spz_normalized`| VARCHAR(20)      | PK; uppercase, bez mezer/pomlček                      |
| `spz_original`  | VARCHAR(20)      | Původní zápis ze zdrojového systému                   |
| `external_id`   | INT UNSIGNED     | ID z DMS (volitelné)                                  |
| `model`         | VARCHAR(100)     | Název vozidla (např. „Škoda Octavia")                 |
| `year`          | SMALLINT UNSIGNED| Rok výroby                                            |
| `vin`           | VARCHAR(17)      | VIN; NULL pokud nevalidní (`[A-HJ-NPR-Z0-9]{17}`)     |
| `updated_at`    | DATETIME         | UTC; čas posledního UPSERT                            |

#### tel_service_orders — pole

| Sloupec         | Typ              | Poznámka                                                       |
|-----------------|------------------|----------------------------------------------------------------|
| `id`            | INT UNSIGNED     | PK, auto increment                                             |
| `source`        | VARCHAR(20)      | `objednano` / `prijem` — zdrojový soubor; index                |
| `scheduled_at`  | DATETIME         | Termín v UTC (zdroj je Europe/Prague); index                   |
| `spz_normalized`| VARCHAR(20)      | Normalizovaná SPZ (NULL u nových vozidel bez SPZ); index       |
| `spz_original`  | VARCHAR(20)      | Původní zápis                                                  |
| `vin`           | VARCHAR(17)      | `fabkod` + `vinkod`; NULL pokud nevalidní; index               |
| `client_name`   | VARCHAR(100)     | Zákazník z plánovače                                           |
| `imported_at`   | DATETIME         | UTC; čas importu snímku                                        |

Žádný unikátní klíč — tabulka je snímek, každý import nahrazuje všechny řádky daného `source`.

#### tel_requests — klíčová pole

| Sloupec           | Typ           | Poznámka                                                    |
|-------------------|---------------|-------------------------------------------------------------|
| `id`              | INT UNSIGNED  | PK AUTO_INCREMENT                                           |
| `spz`             | VARCHAR(20)   | Zobrazovaná SPZ (původní zápis, uppercase)                  |
| `client_name`     | VARCHAR(100)  |                                                             |
| `client_phone`    | VARCHAR(30)   |                                                             |
| `client_email`    | VARCHAR(255)  |                                                             |
| `request_text`    | TEXT          | Max. 2 000 znaků (validace na úrovni aplikace)              |
| `status`          | VARCHAR(20)   | `new` / `in_progress` / `pending` / `resolved` / `reopened` — **VARCHAR, ne ENUM** (lepší migrovatelnost) |
| `pending_reason`  | TEXT          | Povinné při stavu `pending`                                 |
| `reopen_reason`   | TEXT          | Povinné při přechodu do `reopened`                          |
| `created_by`      | INT UNSIGNED  | FK → `tel_users.id`                                         |
| `assigned_to_id`  | INT UNSIGNED  | FK → `tel_users.id`; NULL = nepřevzato                      |
| `assigned_at`     | DATETIME      | UTC                                                         |
| `technician_note` | TEXT          | Poznámka k řešení                                           |
| `created_at`      | DATETIME      | UTC                                                         |
| `updated_at`      | DATETIME      | UTC; aktualizuje se při každé změně                         |
| `resolved_at`     | DATETIME      | UTC; čas posledního uzavření                                |
| `deleted_at`      | DATETIME      | UTC; NULL = aktivní; nenulová = soft deleted                |

#### tel_request_history — klíčová pole

| Sloupec      | Typ          | Poznámka                                                           |
|--------------|--------------|--------------------------------------------------------------------|
| `id`         | INT UNSIGNED | PK                                                                 |
| `request_id` | INT UNSIGNED | FK → `tel_requests.id`                                             |
| `user_id`    | INT UNSIGNED | FK → `tel_users.id`                                                |
| `action`     | VARCHAR(50)  | `created`, `status_change`, `field_edit`, `assigned`, `takeover`, `soft_deleted`, `anonymized`, `reopened` |
| `field_name` | VARCHAR(50)  | Editované pole (NULL pro stavové akce)                             |
| `old_value`  | TEXT         | Max. 500 znaků uložených; delší hodnoty zkráceny s poznámkou `[zkráceno]` |
| `new_value`  | TEXT         | Max. 500 znaků                                                     |
| `created_at` | DATETIME     | UTC                                                                |

#### Indexační strategie

```sql
-- tel_requests
INDEX idx_req_status        (status)
INDEX idx_req_created       (created_at)
INDEX idx_req_assigned      (assigned_to_id)
INDEX idx_req_updated       (updated_at)
INDEX idx_req_deleted       (deleted_at)   -- pro rychlé vyloučení soft-deleted

-- tel_request_history
INDEX idx_hist_request      (request_id)
INDEX idx_hist_request_time (request_id, created_at)
INDEX idx_hist_time         (created_at)

-- tel_rate_limits
INDEX idx_rl_action_ip      (action, ip_address)
INDEX idx_rl_action_email   (action, email)
```

### 6.4 Transakční pravidla

> **Každá operace, která mění stav požadavku nebo jeho pole, MUSÍ proběhnout v jedné MySQL transakci obsahující:**
> 1. UPDATE `tel_requests`
> 2. INSERT do `tel_request_history`
>
> Pokud jeden z kroků selže, transakce se odroluje. Tím je zaručena konzistence dat — žádná změna bez audit záznamu a žádný audit záznam bez odpovídající změny.

### 6.5 Timezone strategie

- **DB ukládá vše v UTC** (`DATETIME`, bez timezone info).
- PHP volá `date_default_timezone_set('Europe/Prague')` — všechny PHP operace s časem pracují v lokálním čase.
- Před zápisem do DB se čas převede na UTC (`gmdate` nebo DateTime s UTC timezone).
- Při výstupu z API se UTC časy převedou na `Europe/Prague` a formátují pro zobrazení.
- JS přijímá časy v ISO 8601 formátu s UTC offsetem; `new Date()` zpracuje správně.

### 6.6 API autentizace

- Všechna API volání vyžadují **aktivní PHP session** (`$_SESSION['user_id']`).
- Neautorizovaný požadavek → HTTP **401** (JSON, bez přesměrování).
- Nedostatečná role → HTTP **403**.
- Každý **POST požadavek** musí obsahovat hlavičku `X-CSRF-Token` shodující se s hodnotou v session.

### 6.7 Bezpečnost

- Hesla: `password_hash()`, bcrypt, cost 12.
- DB: PDO prepared statements.
- CSRF: token generován při přihlášení, ověřován na každý POST.
- Výstup: `htmlspecialchars()`.
- `includes/` a `logs/` chráněny v `.htaccess` (`Deny from all`).
- Reset tokeny: 24 h, jednorázové, nový token invaliduje všechny předchozí.
- Session cookie: `HttpOnly`, `Secure`, `SameSite=Strict`.
- Session timeout nečinnosti: konfigurovatelný (výchozí 120 min).
- Install script: self-disable přes `install.lock`.

**Bezpečnostní HTTP hlavičky** (nastaveny v `.htaccess`):

```apache
Header always set X-Frame-Options "DENY"
Header always set X-Content-Type-Options "nosniff"
Header always set Referrer-Policy "same-origin"
Header always set Content-Security-Policy "default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' https://cdn.jsdelivr.net; font-src 'self' https://cdn.jsdelivr.net"
```

### 6.8 Rate limiting

Implementace: tabulka `tel_rate_limits` (`ip_address`, `email`, `action`, `attempts`, `locked_until`).

| Akce          | Trigger            | Limit              | Lockout                         |
|---------------|--------------------|--------------------|---------------------------------|
| Login         | Neúspěšný pokus    | 5 pokusů / 15 min  | 15 min (dle kombinace IP+email) |
| Reset hesla   | Odeslání formuláře | 3 požadavky / hod  | 60 min (dle e-mailu)            |

- Lockout je vázán na **kombinaci IP + email** (ne jen IP) — zabrání zablokování celé firmy za jednou NAT IP při chybě jednoho uživatele.
- Po lockoutu se aplikuje **exponential backoff**: každý další pokus prodlužuje lockout o 2×.

### 6.9 Nefunkční požadavky

| Požadavek               | Hodnota                                                         |
|-------------------------|-----------------------------------------------------------------|
| Souběžní uživatelé      | < 20 (sdílený hosting dostačuje)                                |
| Požadavky za den        | < 200                                                           |
| Doba odezvy             | < 2 s pro všechny operace                                       |
| Zálohy DB               | Automaticky přes `crony/backup-db.php` na S3; zálohovací cyklus hostingu jako fallback |
| Logování chyb           | PHP error log + `logs/app.log` (přístup zakázán přes HTTP)      |
| Podporované prohlížeče  | Chrome, Firefox, Edge — poslední 2 major verze                  |
| Mobilní zobrazení       | Responzivní (Bootstrap 5), optimalizováno pro tablet            |
| RPO / RTO               | RPO: 24 h (dle zálohovacího cyklu hostingu); RTO: bez definice (interní systém) |

### 6.10 GDPR

- Ukládány osobní údaje klientů: jméno, telefon, e-mail.
- **Právní titul:** oprávněný zájem (servisní vztah) — finální posouzení DPO.
- **Retenční lhůta:** doporučeno 2 roky od `resolved_at`; admin provede anonymizaci.
- Anonymizace nahradí identifikační údaje za `[anonymizováno]`; SPZ a technická data zůstávají.
- Retenční lhůta audit logu: 3 roky od záznamu (logy jsou interní, neobsahují data klienta po anonymizaci).
- Systém nepoužívá analytické služby třetích stran.

---

## 7. Uživatelské příběhy (User Stories)

| ID    | Role      | Příběh                                                                                                     | Priorita  |
|-------|-----------|------------------------------------------------------------------------------------------------------------|-----------|
| US-01 | Recepční  | Chci rychle zadat SPZ, jméno, telefon, e-mail a požadavek, aby technici věděli, co klient chce.           | Musí mít  |
| US-02 | Technik   | Chci vidět přehled aktivních požadavků, který se sám obnovuje.                                            | Musí mít  |
| US-03 | Technik   | Chci vidět, jak starý je každý požadavek, a barevně i ikonkou odlišit urgentní.                           | Musí mít  |
| US-04 | Technik   | Chci převzít požadavek, aby ostatní technici věděli, že ho řeším já.                                      | Musí mít  |
| US-05 | Technik   | Chci otevřít detail, zavolat klientovi a zapsat výsledek hovoru.                                          | Musí mít  |
| US-06 | Technik   | Chci požadavek uzavřít nebo označit jako čekající s uvedením důvodu.                                      | Musí mít  |
| US-07 | Technik   | Chci vyhledat požadavek podle SPZ nebo jména klienta.                                                     | Musí mít  |
| US-08 | Technik   | Chci vidět jen moje převzaté tickety.                                                                     | Musí mít  |
| US-09 | Technik   | Chci dostat upozornění (zvuk/notifikace), když přijde nový ticket.                                        | Musí mít  |
| US-10 | Technik   | Chci vidět varování, pokud někdo mezitím upravil ticket, který právě ukládám.                             | Musí mít  |
| US-11 | Technik   | Chci znovuotevřít vyřízený ticket, pokud klient zavolá znovu.                                             | Musí mít  |
| US-12 | Admin     | Chci přidávat uživatele a přiřazovat jim roli, aniž musím sám nastavovat heslo.                          | Musí mít  |
| US-13 | Admin     | Chci zablokovat uživatele bez nutnosti ho mazat ze systému.                                               | Musí mít  |
| US-14 | Admin     | Chci nastavit prahové hodnoty barev a interval obnovování.                                                 | Musí mít  |
| US-15 | Admin     | Chci zobrazit statistiky – kolik požadavků vyřídil každý technik a jak rychle.                           | Musí mít  |
| US-16 | Admin     | Chci anonymizovat osobní údaje klientů starší zvolené lhůty.                                              | Musí mít  |
| US-17 | Admin     | Chci skrýt (soft-delete) chybně zadaný ticket, aniž bych ho fyzicky smazal.                              | Musí mít  |
| US-18 | Nový user | Chci si sám nastavit heslo přes odkaz zaslaný na e-mail.                                                  | Musí mít  |
| US-19 | Technik   | Chci na kartě vidět model a rok vozidla, abych věděl, s čím budu pracovat, ještě před otevřením detailu. | Musí mít  |
| US-20 | Admin     | Chci synchronizovat databázi vozidel ze S3 ručně nebo přes cron.                                         | Musí mít  |
| US-21 | Admin     | Chci mít automatické zálohy databáze na S3, abych mohl obnovit data v případě výpadku.                   | Musí mít  |
| US-22 | Technik   | Chci na kartě požadavku vidět, na kdy je vozidlo objednané do servisu a na koho, abych klientovi rovnou odpověděl. | Musí mít  |
| US-23 | Admin     | Chci, aby se objednávky z plánovače načítaly automaticky stejným cronem jako vozidla, bez další konfigurace. | Musí mít  |

---

## 8. Mimo rozsah (aktuální verze)

- Odesílání e-mailů / SMS klientovi ze systému.
- Server-side fulltext vyhledávání (ve vyřízených ticketech).
- WebSockets / SSE — polling je vědomý kompromis pro hosting.
- Přesunutí `includes/` mimo webroot (omezení sdíleného hostingu — kompenzováno `.htaccess`).
- Mobilní nativní aplikace.
- Vícejazyčnost.
- Napojení na DMS nebo účetní systém.
- Captcha na přihlašovacím formuláři.
