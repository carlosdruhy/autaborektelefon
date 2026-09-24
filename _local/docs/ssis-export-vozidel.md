# SSIS Export vozidel → CSV

## Cíl
Exportovat tabulku vozidel z MSSQL do CSV souboru na disk.
Synology pak soubor synchronizuje na Amazon S3.

## Požadovaný formát CSV

- Kódování: **UTF-8 bez BOM**
- Oddělovač sloupců: **středník** (`;`)
- Textový kvalifikátor: **uvozovky** (`"`)
- Konce řádků: CRLF
- První řádek: hlavička

Příklad:
```
"klic";"spz";"nazvoz";"rokvyr";"vin"
"1";"1AB2345";"Škoda Octavia";"2018";"TMBJJ7NE5J0123456"
"2";"2CD6789";"Ford Focus";"2020";"WF0FXXGCHFLB12345"
```

## SQL dotaz (OLE DB Source)

```sql
SELECT
    CAST(Id        AS NVARCHAR(20))  AS klic,
    CAST(SPZ       AS NVARCHAR(20))  AS spz,
    CAST(NazevVoz  AS NVARCHAR(100)) AS nazvoz,
    CAST(RokVyroby AS NVARCHAR(10))  AS rokvyr,
    CAST(VIN       AS NVARCHAR(17))  AS vin
FROM dbo.VaseTabVozidel          -- ← upravte název tabulky/view
WHERE aktivni = 1                -- ← upravte filtr dle potřeby
ORDER BY Id
```

> Názvy sloupců `Id`, `SPZ`, `NazevVoz`, `RokVyroby`, `VIN`, `aktivni`
> a název tabulky/view přizpůsobte vaší databázi.

## Konfigurace SSIS balíčku

### 1. OLE DB Connection Manager
- Provider: `SQL Server Native Client` nebo `Microsoft OLE DB Driver for SQL Server`
- Server: váš SQL Server
- Database: vaše databáze

### 2. Flat File Connection Manager (výstup)
| Vlastnost          | Hodnota                          |
|--------------------|----------------------------------|
| File name          | `C:\Export\export-spz.csv`       |
| Locale             | Czech (Czech Republic) nebo jiný |
| Code page          | **65001 (UTF-8)**                |
| Format             | Delimited                        |
| Text qualifier     | `"`                              |
| Header row delim.  | `{CR}{LF}`                       |
| Column names in 1st row | ✔                           |

Sloupce (Flat File Columns):
| Název   | Delimiter | Output col width |
|---------|-----------|-----------------|
| klic    | `;`       | 20              |
| spz     | `;`       | 20              |
| nazvoz  | `;`       | 100             |
| rokvyr  | `;`       | 10              |
| vin     | `{CR}{LF}`| 17              |

### 3. Data Flow Task
- **OLE DB Source** → SQL Command → výše uvedený dotaz
- **Flat File Destination** → výše nastavený Connection Manager

### 4. Plánování (SQL Server Agent)
- Nový Job → Step → typ `SSIS Package`
- Frekvence: denně, např. ve 02:00

## Poznámky
- Sloupec `rokvyr` exportujte jako celé číslo (nebo prázdný řetězec, ne NULL).
- VIN validuje PHP importér: pouze 17 znaků `[A-HJ-NPR-Z0-9]` — ostatní se uloží jako NULL.
- SPZ se normalizuje (bez mezer a pomlček, velká písmena) při importu.

---

# SSIS Export plánovače → CSV

Objednávky do servisu se do aplikace dostávají stejným řetězcem jako vozidla
(SSIS → disk → Synology → Amazon S3 → cron `api/sync-vehicles.php`).

## Soubory

| Soubor                   | Obsah                         | `source` v DB |
|--------------------------|-------------------------------|---------------|
| `planovac-objednano.csv` | Vozidla objednaná do servisu  | `objednano`   |
| `planovac-prijem.csv`    | Plán příjmu vozidel           | `prijem`      |

Oba soubory mají **stejnou strukturu** a exportují se stejným způsobem, liší se
jen filtrem ve zdrojovém dotazu. Aplikace je importuje nezávisle — každý nahradí
v tabulce `tel_service_orders` pouze řádky svého zdroje. Cca 70 % řádků bývá
v obou souborech shodných; aplikace duplicity při zobrazení slučuje.

## Požadovaný formát CSV

- Kódování: **Windows-1250** (současný stav) nebo **UTF-8 bez BOM** — importér zvládne obojí
- Oddělovač sloupců: **středník** (`;`)
- Textový kvalifikátor: **uvozovky** (`"`)
- Konce řádků: CRLF
- První řádek: hlavička — **musí začínat `datum_zac`**, jinak aplikace import odmítne
  (ochrana proti záměně souborů)

Příklad:
```
"datum_zac";"spz";"fabkod";"vinkod";"klient";"stredisko"
"2026-09-21 07:30:00";"1CE 05-23 ";"VF1";"RFK00574387664";"Novák Jan          ";"3"
"2026-09-21 08:00:00";"9C9 65-39 ";"UU1";"DJF00573054256";"GW JIHOTRANS a.s.  ";"33"
```

Hodnoty doplněné mezerami (padding) nevadí — importér je ořezává.

## Sloupce

| Sloupec     | Popis                                                                 |
|-------------|-----------------------------------------------------------------------|
| `datum_zac` | Termín objednávky, formát `YYYY-MM-DD HH:MM:SS`, **lokální čas** (Europe/Prague) |
| `spz`       | SPZ vozidla; smí být prázdná (nové vozidlo bez registrace)            |
| `fabkod`    | První 3 znaky VIN (WMI výrobce)                                        |
| `vinkod`    | Zbývajících 14 znaků VIN                                               |
| `klient`    | Jméno zákazníka (max. 100 znaků)                                       |
| `stredisko` | Kód střediska DMS (`3` = Borek, `33` = Tábor). Aplikace podle něj ukazuje, kde je vůz objednaný (v1.8) |

> **VIN vzniká až v aplikaci spojením `fabkod` + `vinkod`** (3 + 14 = 17 znaků).
> V DMS jsou tyto údaje uložené odděleně, proto se exportují jako dva sloupce.

## SQL dotaz (OLE DB Source)

```sql
SELECT
    CONVERT(NVARCHAR(19), DatumZacatku, 120) AS datum_zac,   -- YYYY-MM-DD HH:MM:SS
    CAST(SPZ      AS NVARCHAR(20))  AS spz,
    CAST(FabKod   AS NVARCHAR(3))   AS fabkod,
    CAST(VinKod   AS NVARCHAR(14))  AS vinkod,
    CAST(Klient   AS NVARCHAR(100)) AS klient,
    CAST(Stredisko AS NVARCHAR(20)) AS stredisko   -- ← kód střediska (v1.8)
FROM dbo.VasePlanovac             -- ← upravte název tabulky/view
WHERE DatumZacatku >= CAST(GETDATE() AS DATE)   -- pouze dnešní a budoucí termíny
  AND TypZaznamu = 'O'            -- ← filtr objednáno / příjem
ORDER BY DatumZacatku
```

> Názvy sloupců, tabulky a hodnotu filtru `TypZaznamu` přizpůsobte vaší databázi.
> Pro `planovac-prijem.csv` se změní pouze tento filtr, zbytek dotazu zůstává.

## Konfigurace SSIS balíčku

Stejná jako u exportu vozidel, jen s jiným Flat File Connection Managerem:

| Vlastnost               | Hodnota                                  |
|-------------------------|------------------------------------------|
| File name               | `C:\Export\planovac-objednano.csv`       |
| Code page               | 1250 (Windows-1250) nebo 65001 (UTF-8)   |
| Format                  | Delimited                                |
| Text qualifier          | `"`                                      |
| Column names in 1st row | ✔                                        |

Sloupce (Flat File Columns):

| Název     | Delimiter  | Output col width |
|-----------|------------|------------------|
| datum_zac | `;`        | 19               |
| spz       | `;`        | 20               |
| fabkod    | `;`        | 3                |
| vinkod    | `;`        | 14               |
| klient    | `;`        | 100              |
| stredisko | `{CR}{LF}` | 20               |

### Plánování (SQL Server Agent)

- Plánovač se mění během dne — doporučená frekvence **každé 2–3 hodiny**
  v pracovní době (cron aplikace běží ve stejném intervalu a stahuje jen
  soubor, jehož ETag se změnil).
- Oba soubory exportujte ve stejném jobu.

### Vyprázdnění souborů před exportem (povinné)

Export do souboru, který už existuje, **připisuje na konec** (v Data Flow chybí přepis
souboru). Soubor pak obsahuje několik exportů za sebou a aplikace ho odmítne
(„Soubor obsahuje hlavičku N×“). Účet SQL Agenta (`NT SERVICE\SQLSERVERAGENT`) má
ke složce právo zápisu, ale ne mazání, takže se soubory **vyprázdní** místo smazání.

**První krok jobu** (typ *Operating system (CmdExec)*, před všemi exporty):

```
cmd /c for %f in ("D:\ntserver\G\Sklad\DMS-NV\*.csv") do type nul > "%f"
```

Pořadí kroků: 1. vyprázdnění → 2. exporty → 3. nahrání na S3. Nahrávání nesmí
běžet souběžně s exportem — soubor nahraný během zápisu má useknutý poslední řádek
a aplikace ho také odmítne („Soubor je neúplný“). V obou případech zůstávají v aplikaci
poslední platná data a chyba je v protokolu na stránce **Admin → Objednávky**.

## Poznámky

- Soubor je vždy **kompletní snímek** aktuálního stavu plánovače, ne přírůstek.
  Zrušená objednávka tím pádem z aplikace zmizí při nejbližší synchronizaci.
- Řádek, který nemá platné datum ani SPZ/VIN, aplikace přeskočí (počet přeskočených
  je vidět v protokolu na stránce **Admin → Objednávky**).
- VIN se validuje stejně jako u vozidel: 17 znaků `[A-HJ-NPR-Z0-9]`, jinak se uloží NULL.
- Historické termíny není nutné exportovat — aplikace zobrazuje jen dnešní a budoucí.
