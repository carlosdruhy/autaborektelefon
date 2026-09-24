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
    'page_size'        => ['label' => 'Počet požadavků na stránku ve výpisu',        'min' => 10,  'max' => 500, 'default' => 50],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(arrStr($_SESSION, 'csrf_token'), arrStr($_POST, 'csrf'))) {
        http_response_code(403);
        die('Neplatný CSRF token.');
    }

    $action = arrStr($_POST, 'action');

    // ── Systémová nastavení ────────────────────────────────────────────────────
    if ($action === '') {
        $postErrors = [];
        foreach ($fields as $key => $cfg) {
            $val = arrInt($_POST, $key);
            if ($val < $cfg['min'] || $val > $cfg['max']) {
                $postErrors[] = "{$cfg['label']}: hodnota musí být {$cfg['min']}–{$cfg['max']}.";
            }
        }
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

    // ── Požadavek ověřovacího kódu pro SMS nastavení ──────────────────────────
    if ($action === 'request_sms_code') {
        $stmt = getDB()->prepare('SELECT email FROM tel_users WHERE id = ? LIMIT 1');
        $stmt->execute([currentUserId()]);
        $row        = pdoFetch($stmt);
        $adminEmail = $row !== false ? arrStr($row, 'email') : '';

        if ($adminEmail === '') {
            $smsError = 'Váš účet nemá nastaven e-mail. Nelze odeslat ověřovací kód.';
        } else {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $_SESSION['sms_edit_code']         = $code;
            $_SESSION['sms_edit_code_expires'] = time() + 600;
            $_SESSION['sms_edit_code_tries']   = 0;

            $subject = 'Ověřovací kód – SMS nastavení – ' . APP_NAME;
            $body    = "Byl podán požadavek na úpravu nastavení SMS (TRB140) v aplikaci " . APP_NAME . ".\n\n"
                     . "Jednorázový kód: {$code}\n\n"
                     . "Platnost: 10 minut.\n\n"
                     . "Pokud jste tento požadavek nepodali, přihlaste se a zkontrolujte aplikaci.";
            $headers = "From: noreply@auto-borek.cz\r\nContent-Type: text/plain; charset=UTF-8";
            mail($adminEmail, $subject, $body, $headers);

            $smsSaved = false;
            $smsError = '';
        }
    }

    // ── Ověření kódu pro SMS nastavení ────────────────────────────────────────
    if ($action === 'verify_sms_code') {
        $inputCode  = arrStr($_POST, 'sms_code');
        $storedCode = arrStr($_SESSION, 'sms_edit_code');
        $expires    = arrInt($_SESSION, 'sms_edit_code_expires');
        $tries      = arrInt($_SESSION, 'sms_edit_code_tries');

        if ($storedCode === '' || time() > $expires) {
            unset($_SESSION['sms_edit_code'], $_SESSION['sms_edit_code_expires'], $_SESSION['sms_edit_code_tries']);
            $smsError = 'Kód vypršel nebo nebyl vygenerován. Začněte znovu.';
        } elseif ($tries >= 3) {
            unset($_SESSION['sms_edit_code'], $_SESSION['sms_edit_code_expires'], $_SESSION['sms_edit_code_tries']);
            $smsError = 'Příliš mnoho nesprávných pokusů. Začněte znovu.';
        } elseif (!hash_equals($storedCode, $inputCode)) {
            $_SESSION['sms_edit_code_tries'] = $tries + 1;
            $remaining = 3 - ($tries + 1);
            $smsError  = 'Nesprávný kód.' . ($remaining > 0 ? " Zbývají {$remaining} pokus(y)." : ' Začněte znovu.');
            if ($remaining <= 0) {
                unset($_SESSION['sms_edit_code'], $_SESSION['sms_edit_code_expires'], $_SESSION['sms_edit_code_tries']);
            }
        } else {
            unset($_SESSION['sms_edit_code'], $_SESSION['sms_edit_code_expires'], $_SESSION['sms_edit_code_tries']);
            $_SESSION['sms_edit_unlocked'] = true;
        }
    }

    // ── Zrušení editace SMS nastavení ─────────────────────────────────────────
    if ($action === 'cancel_sms_edit') {
        unset(
            $_SESSION['sms_edit_code'],
            $_SESSION['sms_edit_code_expires'],
            $_SESSION['sms_edit_code_tries'],
            $_SESSION['sms_edit_unlocked']
        );
    }

    // ── Uložení SMS nastavení (pouze pokud odemčeno) ──────────────────────────
    if ($action === 'sms_settings') {
        if (empty($_SESSION['sms_edit_unlocked'])) {
            http_response_code(403);
            die('SMS nastavení není odemčeno.');
        }
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
            unset($_SESSION['sms_edit_unlocked']);
            $smsSaved = true;
        }
    }

    // ── Záloha DB: uložení prefixu ────────────────────────────────────────────
    if ($action === 'backup_settings') {
        $newPrefix = trim(arrStr($_POST, 's3_backup_prefix'));
        setSetting('s3_backup_prefix', $newPrefix !== '' ? $newPrefix : 'backups');
        $saved = true;
    }

    // ── Záloha DB: regenerace klíče ───────────────────────────────────────────
    if ($action === 'regen_backup_key') {
        setSetting('db_backup_key', bin2hex(random_bytes(24)));
    }

    // ── Anonymizace ───────────────────────────────────────────────────────────
    if ($action === 'anonymize') {
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
}

// ── Záloha DB: auto-generování klíče ─────────────────────────────────────────
$backupKey = getSettingStr('db_backup_key');
if ($backupKey === '') {
    $backupKey = bin2hex(random_bytes(24));
    setSetting('db_backup_key', $backupKey);
}
$backupPrefix = getSettingStr('s3_backup_prefix', 'backups');
$backupLogRaw = getSettingStr('db_backup_log');
$backupLogDecoded = $backupLogRaw !== '' ? json_decode($backupLogRaw, true) : null;
$backupLog = [];
if (is_array($backupLogDecoded)) {
    foreach ($backupLogDecoded as $blEntry) {
        if (is_array($blEntry)) {
            $backupLog[] = $blEntry;
        }
    }
}

// ── Stav sekce SMS nastavení ──────────────────────────────────────────────────
$smsUnlocked   = !empty($_SESSION['sms_edit_unlocked']);
$smsCodePending = !empty($_SESSION['sms_edit_code']);

$settings         = getSettings();
$anonPreviewCount = countAnonymizable($anonDays);

$smsEnabledVal = getSettingStr('sms_enabled') === '1';
$trb140Ip      = getSettingStr('trb140_ip');
$trb140User    = getSettingStr('trb140_user', 'admin');
$trb140Pass    = getSettingStr('trb140_pass');
$smsBridgeKey  = getSettingStr('sms_bridge_key');
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
        <a href="branches.php" class="btn btn-sm btn-outline-light">Pobočky</a>
        <a href="stats.php" class="btn btn-sm btn-outline-light">Statistiky</a>
        <a href="sms.php" class="btn btn-sm btn-outline-light">SMS</a>
        <a href="settings.php" class="btn btn-sm btn-outline-light active">Nastavení</a>
        <a href="import-vehicles.php" class="btn btn-sm btn-outline-light">Vozidla</a>
        <a href="orders.php" class="btn btn-sm btn-outline-light">Objednávky</a>
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

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="post" novalidate>
                <input type="hidden" name="csrf" value="<?= h(arrStr($_SESSION, 'csrf_token')) ?>">
                <input type="hidden" name="action" value="">
                <?php foreach ($fields as $key => $cfg): ?>
                    <div class="mb-3">
                        <label class="form-label" for="<?= h($key) ?>"><?= h($cfg['label']) ?></label>
                        <input type="number" class="form-control" id="<?= h($key) ?>" name="<?= h($key) ?>"
                               value="<?= (int)($settings[$key] ?? $cfg['default'] ?? 0) ?>"
                               min="<?= $cfg['min'] ?>" max="<?= $cfg['max'] ?>" required>
                    </div>
                <?php endforeach; ?>
                <button type="submit" class="btn btn-primary">Uložit nastavení</button>
            </form>
        </div>
    </div>

    <!-- ── SMS nastavení ── -->
    <?php if ($smsUnlocked): ?>
    <!-- STAV 3: Odemčeno -->
    <div class="card shadow-sm mb-4 border-warning">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
            <span>SMS přes TRB140 (Teltonika) <span class="badge bg-warning text-dark ms-2"><i class="bi bi-unlock-fill me-1"></i>Odemčeno</span></span>
            <form method="post" class="m-0">
                <input type="hidden" name="csrf" value="<?= h(arrStr($_SESSION, 'csrf_token')) ?>">
                <input type="hidden" name="action" value="cancel_sms_edit">
                <button type="submit" class="btn btn-sm btn-outline-secondary">Zrušit</button>
            </form>
        </div>
        <div class="card-body">
            <?php if ($smsError): ?>
                <div class="alert alert-danger"><?= h($smsError) ?></div>
            <?php endif; ?>
            <form method="post" novalidate>
                <input type="hidden" name="csrf" value="<?= h(arrStr($_SESSION, 'csrf_token')) ?>">
                <input type="hidden" name="action" value="sms_settings">
                <div class="mb-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="sms_enabled"
                               name="sms_enabled" value="1"
                               <?= $smsEnabledVal ? 'checked' : '' ?>>
                        <label class="form-check-label" for="sms_enabled">Povolit odesílání SMS</label>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="trb140_ip">IP adresa TRB140 (v lokální síti)</label>
                    <input type="text" class="form-control" id="trb140_ip" name="trb140_ip"
                           value="<?= h($trb140Ip) ?>" placeholder="192.168.1.x">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label" for="trb140_user">Uživatel TRB140</label>
                        <input type="text" class="form-control" id="trb140_user" name="trb140_user"
                               value="<?= h($trb140User) ?>" autocomplete="off">
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="trb140_pass">Heslo TRB140</label>
                        <input type="password" class="form-control" id="trb140_pass" name="trb140_pass"
                               value="<?= h($trb140Pass) ?>" autocomplete="new-password">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="sms_bridge_key">Klíč bridge skriptu</label>
                    <div class="input-group">
                        <input type="text" class="form-control font-monospace" id="sms_bridge_key"
                               name="sms_bridge_key" value="<?= h($smsBridgeKey) ?>" autocomplete="off">
                        <button type="button" class="btn btn-outline-secondary" id="genKeyBtn">Generovat</button>
                    </div>
                    <div class="form-text">Tajný klíč, který bridge skript používá pro přístup k frontě SMS.</div>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-floppy me-1"></i>Uložit a zamknout
                </button>
            </form>
        </div>
    </div>

    <?php elseif ($smsCodePending): ?>
    <!-- STAV 2: Čeká na kód -->
    <div class="card shadow-sm mb-4 border-info">
        <div class="card-header fw-semibold">
            SMS přes TRB140 (Teltonika) <span class="badge bg-info text-dark ms-2"><i class="bi bi-envelope me-1"></i>Čeká na kód</span>
        </div>
        <div class="card-body">
            <?php if ($smsError): ?>
                <div class="alert alert-danger"><?= h($smsError) ?></div>
            <?php endif; ?>
            <p class="mb-3">Na váš e-mail byl odeslán 6místný ověřovací kód. Zadejte jej pro odemčení nastavení.</p>
            <form method="post" class="d-flex gap-2 align-items-end flex-wrap">
                <input type="hidden" name="csrf" value="<?= h(arrStr($_SESSION, 'csrf_token')) ?>">
                <input type="hidden" name="action" value="verify_sms_code">
                <div>
                    <label class="form-label small mb-1">Ověřovací kód</label>
                    <input type="text" name="sms_code" class="form-control form-control-sm font-monospace"
                           maxlength="6" pattern="[0-9]{6}" placeholder="123456"
                           autocomplete="one-time-code" autofocus style="width:120px">
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Ověřit</button>
            </form>
            <form method="post" class="mt-2">
                <input type="hidden" name="csrf" value="<?= h(arrStr($_SESSION, 'csrf_token')) ?>">
                <input type="hidden" name="action" value="cancel_sms_edit">
                <button type="submit" class="btn btn-link btn-sm text-muted p-0">Zrušit</button>
            </form>
        </div>
    </div>

    <?php else: ?>
    <!-- STAV 1: Uzamčeno -->
    <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
            <span>SMS přes TRB140 (Teltonika) <i class="bi bi-lock-fill text-muted ms-2 small"></i></span>
            <button type="button" class="btn btn-sm btn-outline-secondary"
                    data-bs-toggle="modal" data-bs-target="#confirmSmsEditModal">
                <i class="bi bi-pencil me-1"></i>Upravit
            </button>
        </div>
        <div class="card-body">
            <?php if ($smsSaved): ?>
                <div class="alert alert-success">Nastavení SMS bylo uloženo. Sekce je opět uzamčena.</div>
            <?php endif; ?>
            <?php if ($smsError): ?>
                <div class="alert alert-danger"><?= h($smsError) ?></div>
            <?php endif; ?>
            <div class="row g-3">
                <div class="col-sm-6">
                    <span class="text-muted small d-block">Odesílání SMS</span>
                    <strong><?= $smsEnabledVal ? '<span class="text-success">Povoleno</span>' : '<span class="text-muted">Zakázáno</span>' ?></strong>
                </div>
                <div class="col-sm-6">
                    <span class="text-muted small d-block">IP adresa TRB140</span>
                    <strong><?= $trb140Ip !== '' ? h($trb140Ip) : '<span class="text-muted">—</span>' ?></strong>
                </div>
                <div class="col-sm-6">
                    <span class="text-muted small d-block">Uživatel TRB140</span>
                    <strong><?= $trb140User !== '' ? h($trb140User) : '<span class="text-muted">—</span>' ?></strong>
                </div>
                <div class="col-sm-6">
                    <span class="text-muted small d-block">Heslo TRB140</span>
                    <strong><?= $trb140Pass !== '' ? '••••••••••••' : '<span class="text-muted">—</span>' ?></strong>
                </div>
                <div class="col-12">
                    <span class="text-muted small d-block">Klíč bridge skriptu</span>
                    <strong><?= $smsBridgeKey !== '' ? '••••••••••••••••' : '<span class="text-muted">—</span>' ?></strong>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Modal: potvrzení žádosti o editaci SMS nastavení -->
    <div class="modal fade" id="confirmSmsEditModal" tabindex="-1" aria-labelledby="confirmSmsEditLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="confirmSmsEditLabel">
                        <i class="bi bi-shield-lock me-2"></i>Upravit nastavení SMS
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Nastavení SMS obsahuje přihlašovací údaje k routeru TRB140 a tajný klíč bridge skriptu.</p>
                    <p>Po potvrzení bude na váš e-mail odeslán <strong>jednorázový ověřovací kód</strong>. Teprve po jeho zadání se sekce odemkne k editaci.</p>
                    <p class="mb-0 text-muted small">Kód je platný 10 minut.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zrušit</button>
                    <form method="post" class="m-0">
                        <input type="hidden" name="csrf" value="<?= h(arrStr($_SESSION, 'csrf_token')) ?>">
                        <input type="hidden" name="action" value="request_sms_code">
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-envelope me-1"></i>Odeslat ověřovací kód
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Záloha databáze ── -->
    <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold">Záloha databáze na S3</div>
        <div class="card-body">

            <!-- Složka v S3 -->
            <form method="post" class="mb-4" novalidate>
                <input type="hidden" name="csrf" value="<?= h(arrStr($_SESSION, 'csrf_token')) ?>">
                <input type="hidden" name="action" value="backup_settings">
                <div class="mb-3">
                    <label class="form-label" for="s3_backup_prefix">Složka v S3 bucketu</label>
                    <input type="text" class="form-control" id="s3_backup_prefix" name="s3_backup_prefix"
                           value="<?= h($backupPrefix) ?>" placeholder="backups">
                    <div class="form-text">
                        Zálohy se ukládají jako <code><?= h($backupPrefix) ?>/telefon-YYYY-MM-DD-HHmm.sql.gz</code>
                        do stejného bucketu jako synchronizace SPZ.
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Uložit složku</button>
            </form>

            <!-- Cron URL -->
            <div class="mb-4">
                <label class="form-label">URL pro cron (každých 24 h)</label>
                <div class="input-group">
                    <input type="text" class="form-control form-control-sm font-monospace" id="backupCronUrl"
                           readonly value="<?= h(APP_URL . '/crony/backup-db.php?key=' . $backupKey) ?>">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="copyBackupUrl">
                        <i class="bi bi-clipboard"></i>
                    </button>
                </div>
            </div>

            <!-- Regenerace klíče -->
            <div class="mb-4">
                <form method="post" class="m-0"
                      onsubmit="return confirm('Regenerací klíče se stávající cron URL stane neplatnou. Pokračovat?')">
                    <input type="hidden" name="csrf" value="<?= h(arrStr($_SESSION, 'csrf_token')) ?>">
                    <input type="hidden" name="action" value="regen_backup_key">
                    <button type="submit" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-repeat me-1"></i>Regenerovat klíč
                    </button>
                </form>
            </div>

            <!-- Log záloh -->
            <?php if ($backupLog): ?>
            <div>
                <div class="text-muted small mb-2">Posledních <?= count($backupLog) ?> pokusů:</div>
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Čas (UTC)</th>
                            <th>Soubor</th>
                            <th>Velikost</th>
                            <th>Výsledek</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($backupLog as $blRow): ?>
                        <tr>
                            <td class="text-nowrap text-muted small"><?= h(arrStr($blRow, 'ts')) ?></td>
                            <td class="font-monospace small"><?= h(arrStr($blRow, 'file')) ?: '—' ?></td>
                            <td class="text-nowrap small">
                                <?php $kb = arrStr($blRow, 'size_kb'); echo $kb !== '' ? h($kb) . ' KB' : '—'; ?>
                            </td>
                            <td>
                                <?php if (arrStr($blRow, 'result') === 'ok'): ?>
                                    <span class="badge bg-success">OK</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Chyba</span>
                                    <?php $msg = arrStr($blRow, 'message'); if ($msg !== ''): ?>
                                    <small class="text-danger ms-1"><?= h($msg) ?></small>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p class="text-muted small mb-0">Zatím žádná záloha neproběhla.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Anonymizace ── -->
    <div class="card shadow-sm border-warning mb-4">
        <div class="card-header fw-semibold">Anonymizace osobních údajů (GDPR)</div>
        <div class="card-body">
            <?php if ($anonSaved): ?>
                <div class="alert alert-success">Anonymizováno <?= $anonCount ?> požadavků.</div>
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
                <input type="hidden" name="csrf" value="<?= h(arrStr($_SESSION, 'csrf_token')) ?>">
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
<?php if ($smsUnlocked): ?>
document.getElementById('genKeyBtn').addEventListener('click', function () {
    const arr = new Uint8Array(24);
    crypto.getRandomValues(arr);
    document.getElementById('sms_bridge_key').value =
        Array.from(arr).map(b => b.toString(16).padStart(2, '0')).join('');
});
<?php endif; ?>

document.getElementById('copyBackupUrl').addEventListener('click', function () {
    const input = document.getElementById('backupCronUrl');
    navigator.clipboard.writeText(input.value).then(() => {
        this.innerHTML = '<i class="bi bi-check"></i>';
        setTimeout(() => { this.innerHTML = '<i class="bi bi-clipboard"></i>'; }, 1500);
    });
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
