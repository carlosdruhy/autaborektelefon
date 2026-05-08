# SMS Bridge – PowerShell
# Spuštění ručně:  powershell -ExecutionPolicy Bypass -File sms-bridge.ps1
# Task Scheduler:  každou 1 minutu (viz návod níže)

$AppUrl     = 'https://tel.auto-borek.cz'
$BridgeKey  = 'SEM_VLOZTE_BRIDGE_KLIC'    # zkopírujte z Admin > Nastavení
$Trb140Ip   = '192.168.1.XXX'             # IP TRB140 v lokální síti
$Trb140User = 'admin'
$Trb140Pass = 'SEM_HESLO_TRB140'

# ── Logování ──────────────────────────────────────────────────────────────────

function Log-Msg([string]$msg) {
    $line = "[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $msg
    Write-Host $line
    Add-Content -Path "$PSScriptRoot\sms-bridge.log" -Value $line -Encoding UTF8
}

# ── 1. Stáhnout čekající SMS z fronty aplikace ────────────────────────────────

try {
    $encodedKey = [uri]::EscapeDataString($BridgeKey)
    $pending    = Invoke-RestMethod -Uri "$AppUrl/api/sms.php?action=pending&key=$encodedKey" `
                                    -Method GET -UseBasicParsing
} catch {
    Log-Msg "Chyba pri nacitani fronty: $_"
    exit 1
}

if (-not $pending.success) {
    Log-Msg "API chyba: $($pending.error)"
    exit 1
}

# @() zajisti, ze promenna je vzdy pole (i pro jediny prvek)
$items = @($pending.data)

if ($items.Count -eq 0) {
    Log-Msg "Fronta prazdna, nic k odeslani."
    exit 0
}

Log-Msg "Nalezeno $($items.Count) SMS ke zpracovani."

# ── 2. Prihlasit se k TRB140 ─────────────────────────────────────────────────

try {
    $loginBody = @{ username = $Trb140User; password = $Trb140Pass } | ConvertTo-Json
    $loginResp = Invoke-RestMethod -Uri "http://$Trb140Ip/api/login" `
                                   -Method POST -ContentType 'application/json' -Body $loginBody `
                                   -UseBasicParsing
} catch {
    Log-Msg "Prihlaseni k TRB140 selhalo: $_"
    exit 1
}

if (-not $loginResp.success) {
    Log-Msg "TRB140 odmitlo prihlaseni."
    exit 1
}

$token   = $loginResp.data.token
$headers = @{ Authorization = "Bearer $token" }

# ── 3. Odeslat SMS pres TRB140 ────────────────────────────────────────────────

$results = @()

foreach ($sms in $items) {
    $id      = [int]$sms.id
    $phone   = [string]$sms.phone
    $message = [string]$sms.message

    try {
        $smsBody = @{ data = @{ send_to = $phone; message = $message } } | ConvertTo-Json -Depth 3
        $smsResp = Invoke-RestMethod -Uri "http://$Trb140Ip/api/sms/send" `
                                     -Method POST -ContentType 'application/json' `
                                     -Headers $headers -Body $smsBody -UseBasicParsing
        $success = $smsResp.success -eq $true
        $errMsg  = $null
    } catch {
        $success = $false
        $raw     = $_.Exception.Message
        $errMsg  = if ($raw.Length -gt 255) { $raw.Substring(0, 255) } else { $raw }
    }

    if ($success) {
        Log-Msg "SMS #$id -> $phone`: OK"
    } else {
        Log-Msg "SMS #$id -> $phone`: CHYBA: $errMsg"
    }

    $results += @{ id = $id; success = $success; error = $errMsg }
}

# ── 4. Potvrdit vysledky zpet do aplikace ─────────────────────────────────────

try {
    $confirmBody = @{ results = @($results) } | ConvertTo-Json -Depth 3
    $confirmResp = Invoke-RestMethod -Uri "$AppUrl/api/sms.php?action=confirm&key=$encodedKey" `
                                     -Method POST -ContentType 'application/json' `
                                     -Body $confirmBody -UseBasicParsing
} catch {
    Log-Msg "Chyba pri potvrzovani vysledku: $_"
    exit 1
}

Log-Msg "Hotovo. Aktualizovano zaznamu: $($confirmResp.data.updated)"
exit 0

<#
──────────────────────────────────────────────────────────────────────────────
NASTAVENI WINDOWS TASK SCHEDULER (spousted jako administrator):

1. Otevrete Task Scheduler (taskschd.msc)
2. Akce > Vytvorit zakladni ulohu
3. Nazev: SMS Bridge
4. Trigger: Opakovat kazkou 1 minutu, trvani: Neurčito
5. Akce: Spustit program
   Program:   powershell.exe
   Argumenty: -ExecutionPolicy Bypass -WindowStyle Hidden -File "C:\cesta\sms-bridge.ps1"
6. Dokoncit, zadat heslo uctu Windows pokud pozadovano

Overeni: kliknete pravym > Spustit — v logu sms-bridge.log uvidite vysledek.
──────────────────────────────────────────────────────────────────────────────
#>
