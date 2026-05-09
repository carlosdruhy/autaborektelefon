<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

startSecureSession();
requireAdmin();
checkSessionTimeout();
touchSession();

$saved = false;
$error = '';

$smsSaved = false;
$smsError = '';

$anonSaved = false;
$anonError = '';
$anonCount = 0;
$anonDays  = arrInt($_POST, 'anon_days', 730);
if ($anonDays < 30 || $anonDays > 3650) {
    $anonDays = 730;
}

$fields = [
    'refresh_interval' => ['label' => 'Interval automatické aktualizace (sekund)', 'min' => 5,   'max' => 600],
    'color_level_1'    => ['label' => 'Práh úrovně 1→2 (minuty)',                  'min' => 1,   'max' => 1440],
    'color_level_2'    => ['label' => 'Práh úrovně 2→3 (minuty)',                  'min' => 1,   'max' => 1440],
    'color_level_3'    => ['label' => 'Práh úrovně 3→4 (minuty)',                  'min' => 1,   'max' => 1440],
    'color_level_4'    => ['label' => 'Práh úrovně 4→5 (minuty)',                  'min' => 1,   'max' => 1440],
    'session_timeout'  => ['label' => 'Timeout nečinnosti session (minuty)',        'min' => 5,   'max' => 1440],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sms_settings') {
    $smsIp      = trim(arrStr($_POST, 'trb140_ip'));
    $smsUser    = trim(arrStr($_POST, 'trb140_user'));
    $smsPass    = trim(arrStr($_POST, 'trb140_pass'));
    $smsBKey    = trim(arrStr($_POST, 'sms_bridge_key'));
    $smsEnabled = ($_POST['sms_enabled'] ?? '0') === '1' ? '1' : '0';

    if ($smsEnabled === '1' && $smsIp === '') {
        $smsError = 'Zadejte IP adresu TRB140.';
    } elseif ($smsEnabled === '1' && $smsBKey === '') {
        $smsError = 'Zadejte klíč bridge skriptu.';
    } else {
        setSetting('sms_enabled',    $smsEnabled);
        setSetting('trb140_ip',      $smsIp);
        setSetting('trb140_user',    $smsUser);
        setSetting('trb140_pass',    $smsPass);
        setSetting('sms_bridge_key', $smsBKey);
        $smsSaved = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'anonymize') {
    if (arrInt($_POST, 'anon_days') < 30 || arrInt($_POST, 'anon_days') > 3650) {
        $anonError = 'Počet dnů musí být mezi 30 a 3 650.';
    } else {
        try {
            $anonCount = anonymizeRequests($anonDays, currentUserId());
            $anonSaved = true;
        } catch (Throwable $e) {
            $anonError = 'Chyba při anonymizaci: ' . h($e->getMessage());
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'anonymize') {
    $postErrors = [];
    foreach ($fields as $key => $cfg) {
        $val = arrInt($_POST, $key);
        if ($val < $cfg['min'] || $val > $cfg['max']) {
            $postErrors[] = "{$cfg['label']}: hodnota musí být {$cfg['min']}–{$cfg['max']}.";
        }
    }

    // Validace pořadí prahů
    $t = array_map(fn($k) => arrInt($_POST, $k), ['color_level_1','color_level_2','color_level_3','color_level_4']);
    if ($t[0] >= $t[1] || $t[1] >= $t[2] || $t[2] >= $t[3]) {
        $postErrors[] = 'Prahové hodnoty musí být v rostoucím pořadí.';
    }

    if ($postErrors) {
        $error = implode('<br>', array_map(fn($e) => h($e), $postErrors));
    } else {
        foreach ($fields as $key => $cfg) {
            setSetting($key, (string)arrInt($_POST, $key));
        }
        $saved = true;
    }
}

$settings = getSettings();
$anonPreviewCount = countAnonymizable($anonDays);
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Nastavení – Admin – <?= h(APP_NAME) ?></title>
<link rel="icon" type="image/svg+xml" href="../favicon.svg">
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#3d3d3d">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Telefon">
<link rel="apple-touch-icon" href="/favicon.svg">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="<?= assetUrl('assets/css/style.css') ?>">
</head>
<body>
<nav class="navbar navbar-dark navbar-expand-sm app-navbar px-3">
    <a class="navbar-brand d-flex align-items-center gap-2 text-decoration-none" href="../dashboard.php">
        <span class="navbar-logo-wrap">
            <img src="../assets/img/logo.jpg" alt="Auta Borek a.s.">
        </span>
        <span class="navbar-app-title d-none d-md-block">Administrace</span>
    </a>
    <div class="ms-auto d-flex gap-2">
        <a href="../dashboard.php" class="btn btn-sm btn-outline-light">Přehled</a>
        <a href="stats.php" class="btn btn-sm btn-outline-light">Statistiky</a>
        <a href="sms.php" class="btn btn-sm btn-outline-light">SMS</a>
        <a href="settings.php" class="btn btn-sm btn-outline-light">Nastavení</a>
        <a href="../logout.php" class="btn btn-sm btn-outline-light">Odhlásit</a>
    </div>
</nav>

<div class="container py-4" style="max-width:640px">
    <h1 class="h4 mb-4">Nastavení systému</h1>

    <?php if ($saved): ?>
        <div class="alert alert-success">Nastavení bylo uloženo.</div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="post" novalidate>
                <?php foreach ($fields as $key => $cfg): ?>
                    <div class="mb-3">
                        <label class="form-label" for="<?= h($key) ?>"><?= h($cfg['label']) ?></label>
                        <input type="number" class="form-control" id="<?= h($key) ?>" name="<?= h($key) ?>"
                               value="<?= (int)($settings[$key] ?? 0) ?>"
                               min="<?= $cfg['min'] ?>" max="<?= $cfg['max'] ?>" required>
                    </div>
                <?php endforeach; ?>
                <button type="submit" class="btn btn-primary">Uložit nastavení</button>
            </form>
        </div>
    </div>
</div>

<div class="container py-2" style="max-width:640px">
    <div class="card shadow-sm">
        <div class="card-header fw-semibold">SMS přes TRB140 (Teltonika)</div>
        <div class="card-body">

            <?php if ($smsSaved): ?>
                <div class="alert alert-success">Nastavení SMS bylo uloženo.</div>
            <?php endif; ?>
            <?php if ($smsError): ?>
                <div class="alert alert-danger"><?= h($smsError) ?></div>
            <?php endif; ?>

            <form method="post" novalidate>
                <input type="hidden" name="action" value="sms_settings">

                <div class="mb-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="sms_enabled"
                               name="sms_enabled" value="1"
                               <?= getSettingStr('sms_enabled') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="sms_enabled">Povolit odesílání SMS</label>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="trb140_ip">IP adresa TRB140 (v lokální síti)</label>
                    <input type="text" class="form-control" id="trb140_ip" name="trb140_ip"
                           value="<?= h(getSettingStr('trb140_ip')) ?>"
                           placeholder="192.168.1.x">
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label" for="trb140_user">Uživatel TRB140</label>
                        <input type="text" class="form-control" id="trb140_user" name="trb140_user"
                               value="<?= h(getSettingStr('trb140_user', 'admin')) ?>"
                               autocomplete="off">
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="trb140_pass">Heslo TRB140</label>
                        <input type="password" class="form-control" id="trb140_pass" name="trb140_pass"
                               value="<?= h(getSettingStr('trb140_pass')) ?>"
                               autocomplete="new-password">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="sms_bridge_key">Klíč bridge skriptu</label>
                    <div class="input-group">
                        <input type="text" class="form-control font-monospace" id="sms_bridge_key"
                               name="sms_bridge_key"
                               value="<?= h(getSettingStr('sms_bridge_key')) ?>"
                               autocomplete="off">
                        <button type="button" class="btn btn-outline-secondary" id="genKeyBtn">Generovat</button>
                    </div>
                    <div class="form-text">Tajný klíč, který bridge skript používá pro přístup k frontě SMS.</div>
                </div>

                <button type="submit" class="btn btn-primary">Uložit nastavení SMS</button>
            </form>
        </div>
    </div>
</div>

<div class="container py-2 pb-4" style="max-width:640px">
    <div class="card shadow-sm border-warning">
        <div class="card-header fw-semibold">Anonymizace osobních údajů (GDPR)</div>
        <div class="card-body">

            <?php if ($anonSaved): ?>
                <div class="alert alert-success">
                    Anonymizováno <?= $anonCount ?> požadavků.
                </div>
            <?php endif; ?>
            <?php if ($anonError): ?>
                <div class="alert alert-danger"><?= $anonError ?></div>
            <?php endif; ?>

            <p class="text-muted small mb-3">
                Nahradí jméno, telefon a e-mail klienta za <code>[anonymizováno]</code>
                u vyřízených požadavků starších než zadaný počet dní.
                SPZ, text požadavku a technická data zůstanou zachována. Akce je <strong>nevratná</strong>.
            </p>

            <form method="post" novalidate id="anonForm">
                <input type="hidden" name="action" value="anonymize">
                <div class="mb-3">
                    <label class="form-label" for="anon_days">
                        Anonymizovat vyřízené požadavky starší než (dní)
                    </label>
                    <input type="number" class="form-control" id="anon_days" name="anon_days"
                           value="<?= $anonDays ?>" min="30" max="3650" required>
                    <div class="form-text">
                        Doporučeno 730 dní (2 roky). Při aktuálním nastavení
                        <strong><?= $anonPreviewCount ?></strong> záznamů ke zpracování.
                    </div>
                </div>
                <button type="submit" class="btn btn-warning">Spustit anonymizaci</button>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('genKeyBtn').addEventListener('click', function () {
    const arr = new Uint8Array(24);
    crypto.getRandomValues(arr);
    document.getElementById('sms_bridge_key').value =
        Array.from(arr).map(b => b.toString(16).padStart(2, '0')).join('');
});

document.getElementById('anonForm').addEventListener('submit', function (e) {
    const days = document.getElementById('anon_days').value;
    if (!confirm('Opravdu chcete anonymizovat osobní údaje klientů v požadavcích starších než ' + days + ' dní?\n\nTato akce je nevratná.')) {
        e.preventDefault();
    }
});
</script>
</body>
</html>
