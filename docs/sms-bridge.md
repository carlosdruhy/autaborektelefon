# Nastavení SMS bridge – PC ve firmě

Tento dokument popisuje, jak nastavit odesílání SMS přes router Teltonika TRB140
na Windows 11 počítači v lokální síti firmy.

---

## Přehled architektury

```
tel.auto-borek.cz          Firemní síť (LAN)
┌─────────────────┐        ┌──────────────────┐      ┌──────────────┐
│  Webová aplikace│◄──────►│  sms-bridge.ps1  │─────►│  TRB140      │
│  (SMS fronta    │  HTTPS │  (PC ve firmě,   │ HTTP │  (lokální IP)│──► SIM → SMS
│   v databázi)   │        │  Task Scheduler) │      └──────────────┘
└─────────────────┘        └──────────────────┘
```

Bridge skript běží každou minutu, stáhne čekající SMS z fronty aplikace,
odešle je přes TRB140 a potvrdí výsledky zpět do aplikace.

---

## Předpoklady

- Windows 11 (PowerShell 5.1 je součástí systému, nic se neinstaluje)
- PC musí být **zapnutý** a **připojený do LAN** (nebo VPN) pokud má odesílat SMS
- TRB140 musí být **dostupný z tohoto PC** přes HTTP na lokální IP adrese
- V aplikaci musí být SMS funkce povolena a nakonfigurována (Admin → Nastavení)

---

## Krok 1 — Zjistěte IP adresu TRB140

1. Otevřete prohlížeč a přejděte na výchozí IP adresu TRB140.  
   Výchozí adresa bývá `192.168.1.1` nebo `192.168.2.1` — ověřte štítek na zařízení.
2. Přihlaste se do administrace TRB140 (výchozí: `admin` / heslo ze štítku).
3. V menu **Status → Overview** nebo **Network → LAN** zjistěte aktuální IP adresu zařízení.
4. Doporučujeme TRB140 nastavit **statickou IP** v LAN (aby se IP neměnila po restartu).

---

## Krok 2 — Nastavte aplikaci (Admin → Nastavení)

Přihlaste se do aplikace jako admin, přejděte na **Admin → Nastavení**, sekce **SMS přes TRB140**:

| Pole | Hodnota |
|---|---|
| Povolit odesílání SMS | ✓ (zaškrtnout) |
| IP adresa TRB140 | IP zjištěná v Kroku 1, např. `192.168.1.100` |
| Uživatel TRB140 | `admin` (nebo váš uživatel) |
| Heslo TRB140 | heslo z administrace TRB140 |
| Klíč bridge skriptu | klikněte **Generovat** a klíč si zkopírujte |

Klikněte **Uložit nastavení SMS**. Vygenerovaný klíč budete potřebovat v Kroku 3.

---

## Krok 3 — Připravte bridge skript

1. Zkopírujte soubor `sms-bridge.ps1` na PC ve firmě.  
   Doporučené umístění: `C:\SMS-Bridge\sms-bridge.ps1`

2. Otevřete soubor v Poznámkovém bloku (pravý klik → Otevřít v → Poznámkový blok)
   a upravte čtyři konstanty na začátku:

```powershell
$AppUrl     = 'https://tel.auto-borek.cz'   # neměňte
$BridgeKey  = 'zkopírujte klíč z nastavení aplikace'
$Trb140Ip   = '192.168.1.100'               # IP z Kroku 1
$Trb140User = 'admin'                        # uživatel TRB140
$Trb140Pass = 'heslo_trb140'                 # heslo TRB140
```

3. Uložte soubor.

---

## Krok 4 — Ověřte funkčnost ručně

1. Klikněte pravým tlačítkem na Start → **Windows PowerShell (správce)**
2. Spusťte:
   ```powershell
   powershell -ExecutionPolicy Bypass -File "C:\SMS-Bridge\sms-bridge.ps1"
   ```
3. Očekávaný výstup (pokud je fronta prázdná):
   ```
   [2025-05-08 10:00:00] Fronta prazdna, nic k odeslani.
   ```
   Pokud vidíte chybu přihlášení k TRB140, ověřte IP adresu a heslo.

4. Pro test s reálnou SMS: zadejte v aplikaci požadavek s telefonním číslem,
   klikněte **Odeslat SMS**, napište text a potvrďte. Pak znovu spusťte skript —
   SMS by měla odejít a výstup bude:
   ```
   [2025-05-08 10:00:05] Nalezeno 1 SMS ke zpracovani.
   [2025-05-08 10:00:06] SMS #1 -> +420123456789: OK
   [2025-05-08 10:00:06] Hotovo. Aktualizovano zaznamu: 1
   ```

---

## Krok 5 — Nastavte automatické spouštění (Task Scheduler)

1. Otevřete **Plánovač úloh** (Task Scheduler):  
   Start → hledat `taskschd.msc` → spustit jako správce

2. V pravém panelu klikněte **Vytvořit základní úlohu…**

3. Vyplňte průvodce:

   | Krok | Hodnota |
   |---|---|
   | Název | `SMS Bridge` |
   | Popis | Odesílání SMS přes TRB140 |
   | Trigger | Denně |
   | Opakovat každých | 1 minuta, po dobu: Neurčito |
   | Akce | Spustit program |
   | Program | `powershell.exe` |
   | Argumenty | `-ExecutionPolicy Bypass -WindowStyle Hidden -File "C:\SMS-Bridge\sms-bridge.ps1"` |

4. Na poslední stránce zaškrtněte **Otevřít dialog vlastností po dokončení** a klikněte Dokončit.

5. V dialogu vlastností:
   - Záložka **Obecné**: vyberte **Spustit bez ohledu na přihlášení uživatele**,
     zadejte heslo k účtu Windows
   - Záložka **Nastavení**: zaškrtněte **Spustit úlohu co nejdříve po zpoždění**

6. Klikněte OK a zadejte heslo Windows účtu.

7. Ověřte: v levém panelu klikněte na **Knihovna Plánovače úloh**, najděte
   `SMS Bridge`, pravý klik → **Spustit**. Zkontrolujte soubor
   `C:\SMS-Bridge\sms-bridge.log`.

---

## Log soubor

Každý běh skriptu zapisuje výsledky do:
```
C:\SMS-Bridge\sms-bridge.log
```

Příklad záznamu:
```
[2025-05-08 10:01:00] Fronta prazdna, nic k odeslani.
[2025-05-08 10:02:00] Nalezeno 2 SMS ke zpracovani.
[2025-05-08 10:02:01] SMS #5 -> +420111222333: OK
[2025-05-08 10:02:02] SMS #6 -> +420444555666: CHYBA: ...
[2025-05-08 10:02:02] Hotovo. Aktualizovano zaznamu: 2
```

Log není automaticky promazáván — doporučujeme jednou za čas smazat nebo archivovat.

---

## Řešení problémů

### Skript hlásí „Chyba pri nacitani fronty"
- PC nemá přístup na internet nebo na `tel.auto-borek.cz`
- Bridge klíč v konstantě `$BridgeKey` nesouhlasí s klíčem v nastavení aplikace

### Skript hlásí „Prihlaseni k TRB140 selhalo"
- IP adresa TRB140 je špatná — ověřte pingem: `ping 192.168.1.100`
- Heslo TRB140 je nesprávné
- TRB140 má vypnuté HTTP API — v administraci TRB140 ověřte **Services → API**

### SMS odejde ale příjemce ji nedostane
- Ověřte kredit SIM karty v TRB140
- Ověřte, že SIM má aktivní datové/hlasové služby (SMS nevyžaduje data)
- Číslo musí být ve formátu s předvolbou: `+420xxxxxxxxx`

### Skript v Task Scheduleru nespouští
- Ověřte, že cesta k skriptu v argumentech je správná a bez překlepů
- Spusťte úlohu ručně (pravý klik → Spustit) a zkontrolujte log
- Zkontrolujte, zda účet Windows má právo spouštět PowerShell skripty

---

## Bezpečnostní poznámky

- Skript obsahuje heslo k TRB140 v čitelné podobě — soubor udržujte na PC
  ve firmě a nepřenášejte e-mailem ani cloudovými úložišti
- Bridge klíč slouží jako autentizace bridge → aplikace; nesdělejte ho třetím stranám
- TRB140 není dostupné z internetu — komunikace probíhá pouze v LAN, což je bezpečné
