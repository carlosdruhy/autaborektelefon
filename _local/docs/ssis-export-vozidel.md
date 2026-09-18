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
