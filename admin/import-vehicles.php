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

$csvPath = __DIR__ . '/../dataupload/export-spz.csv';
$db      = getDB();

// ─── Statistiky z DB ──────────────────────────────────────────────────────────
$countStmt = $db->query('SELECT COUNT(*) FROM tel_vehicles');
$countInDb = $countStmt !== false ? (int) $countStmt->fetchColumn() : 0;

$lastSync           = getSettingStr('vehicles_last_sync');
$lastSyncCount      = getSettingStr('vehicles_last_sync_count');
$s3FileLastModified = getSettingStr('s3_file_last_modified');

$syncLogRaw     = getSettingStr('vehicles_sync_log');
$syncLogDecoded = $syncLogRaw !== '' ? json_decode($syncLogRaw, true) : null;
$syncLog        = [];
if (is_array($syncLogDecoded)) {
    foreach ($syncLogDecoded as $item) {
        if (is_array($item)) {
            $syncLog[] = $item;
        }
    }
}

// ─── Zpracování formulářů ─────────────────────────────────────────────────────
$result     = null;
$resultType = '';
$flashOk    = '';
$flashErr   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(arrStr($_SESSION, 'csrf_token'), arrStr($_POST, 'csrf'))) {
        http_response_code(403);
        die('Neplatný CSRF token.');
    }

    $action = arrStr($_POST, 'action');

    // Požadavek ověřovacího kódu pro odemčení S3 nastavení
    if ($action === 'request_s3_code') {
        $stmt = $db->prepare('SELECT email FROM tel_users WHERE id = ? LIMIT 1');
        $stmt->execute([currentUserId()]);
        $row        = pdoFetch($stmt);
        $adminEmail = $row !== false ? arrStr($row, 'email') : '';

        if ($adminEmail === '') {
            $flashErr = 'Váš účet nemá nastaven e-mail. Nelze odeslat ověřovací kód.';
        } else {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $_SESSION['s3_edit_code']         = $code;
            $_SESSION['s3_edit_code_expires'] = time() + 600;
            $_SESSION['s3_edit_code_tries']   = 0;

            $subject = 'Ověřovací kód – S3 nastavení – ' . APP_NAME;
            $body    = "Byl podán požadavek na úpravu nastavení Amazon S3 v aplikaci " . APP_NAME . ".\n\n"
                     . "Jednorázový kód: {$code}\n\n"
                     . "Platnost: 10 minut.\n\n"
                     . "Pokud jste tento požadavek nepodali, přihlaste se a zkontrolujte aplikaci.";
            $headers = "From: noreply@auto-borek.cz\r\nContent-Type: text/plain; charset=UTF-8";
            mail($adminEmail, $subject, $body, $headers);

            $flashOk = 'Ověřovací kód byl odeslán na ' . $adminEmail . '.';
        }
    }

    // Ověření kódu
    if ($action === 'verify_s3_code') {
        $inputCode  = arrStr($_POST, 's3_code');
        $storedCode = arrStr($_SESSION, 's3_edit_code');
        $expires    = arrInt($_SESSION, 's3_edit_code_expires');
        $tries      = arrInt($_SESSION, 's3_edit_code_tries');

        if ($storedCode === '' || time() > $expires) {
            unset($_SESSION['s3_edit_code'], $_SESSION['s3_edit_code_expires'], $_SESSION['s3_edit_code_tries']);
            $flashErr = 'Kód vypršel nebo nebyl vygenerován. Začněte znovu.';
        } elseif ($tries >= 3) {
            unset($_SESSION['s3_edit_code'], $_SESSION['s3_edit_code_expires'], $_SESSION['s3_edit_code_tries']);
            $flashErr = 'Příliš mnoho nesprávných pokusů. Začněte znovu.';
        } elseif (!hash_equals($storedCode, $inputCode)) {
            $_SESSION['s3_edit_code_tries'] = $tries + 1;
            $remaining = 3 - ($tries + 1);
            $flashErr  = 'Nesprávný kód.' . ($remaining > 0 ? " Zbývají {$remaining} pokus(y)." : ' Začněte znovu.');
            if ($remaining <= 0) {
                unset($_SESSION['s3_edit_code'], $_SESSION['s3_edit_code_expires'], $_SESSION['s3_edit_code_tries']);
            }
        } else {
            unset($_SESSION['s3_edit_code'], $_SESSION['s3_edit_code_expires'], $_SESSION['s3_edit_code_tries']);
            $_SESSION['s3_edit_unlocked'] = true;
            $flashOk = 'Nastavení S3 odemčeno. Proveďte změny a uložte.';
        }
    }

    // Zrušení editace / zrušení čekání na kód
    if ($action === 'cancel_s3_edit') {
        unset(
            $_SESSION['s3_edit_code'],
            $_SESSION['s3_edit_code_expires'],
            $_SESSION['s3_edit_code_tries'],
            $_SESSION['s3_edit_unlocked']
        );
    }

    // Uložit S3 nastavení (pouze pokud je sekce odemčena)
    if ($action === 'save_s3') {
        if (empty($_SESSION['s3_edit_unlocked'])) {
            http_response_code(403);
            die('Nastavení S3 není odemčeno.');
        }
        $fields = ['s3_region', 's3_bucket', 's3_object_key', 's3_orders_object_key', 's3_prijem_object_key', 's3_access_key_id', 's3_secret_access_key', 'vehicles_sync_key'];
        foreach ($fields as $f) {
            setSetting($f, arrStr($_POST, $f));
        }
        unset($_SESSION['s3_edit_unlocked']);
        $flashOk    = 'Nastavení uložena. Sekce je opět uzamčena.';
        $resultType = 'settings';
    }

    // Import z lokálního souboru
    if ($action === 'import') {
        set_time_limit(120);
        if (!is_file($csvPath)) {
            $flashErr = 'Soubor nenalezen: ' . $csvPath;
        } else {
            $result     = importVehiclesCsv($db, $csvPath);
            $resultType = 'file';
        }
    }

    // Ruční synchronizace ze S3 (ignoruje ETag — stahuje vždy)
    if ($action === 'sync_s3') {
        set_time_limit(120);
        $sync = syncVehiclesFromS3('manual');
        if ($sync['status'] === 'not_configured') {
            $flashErr = 'S3 není nakonfigurováno. Vyplňte nastavení níže.';
        } elseif ($sync['status'] !== 'synced') {
            $flashErr = 'Synchronizace selhala: ' . $sync['message'];
        } else {
            $result = [
                'inserted' => $sync['inserted'],
                'updated'  => $sync['updated'],
                'skipped'  => $sync['skipped'],
                'errors'   => [],
            ];
            $resultType    = 's3';
            $lastSync      = getSettingStr('vehicles_last_sync');
            $lastSyncCount = getSettingStr('vehicles_last_sync_count');
            $s3FileLastModified = getSettingStr('s3_file_last_modified');
        }
    }
}

// ─── Stav sekce S3 nastavení ──────────────────────────────────────────────────
$s3Unlocked   = !empty($_SESSION['s3_edit_unlocked']);
$s3CodePending = !empty($_SESSION['s3_edit_code']);

// S3 nastavení pro zobrazení / formulář
$s3Region    = getSettingStr('s3_region');
$s3Bucket    = getSettingStr('s3_bucket');
$s3ObjectKey = getSettingStr('s3_object_key');
$s3OrdersKey = getSettingStr('s3_orders_object_key');
$s3PrijemKey = getSettingStr('s3_prijem_object_key');
$s3KeyId     = getSettingStr('s3_access_key_id');
$s3Secret    = getSettingStr('s3_secret_access_key');
$syncKey     = getSettingStr('vehicles_sync_key');

$s3Configured = ($s3KeyId !== '' && $s3Secret !== '' && $s3Region !== '' && $s3Bucket !== '' && $s3ObjectKey !== '');

?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Vozidla – Admin – <?= h(APP_NAME) ?></title>
<link rel="icon" type="image/svg+xml" href="../favicon.svg">
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#3d3d3d">
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
        <a href="settings.php" class="btn btn-sm btn-outline-light">Nastavení</a>
        <a href="import-vehicles.php" class="btn btn-sm btn-outline-light active">Vozidla</a>
        <a href="orders.php" class="btn btn-sm btn-outline-light">Objednávky</a>
        <a href="../logout.php" class="btn btn-sm btn-outline-light">Odhlásit</a>
    </div>
</nav>

<div class="container py-4" style="max-width:760px">
    <h1 class="h4 mb-4">Evidence vozidel</h1>

    <?php if ($flashOk): ?>
        <div class="alert alert-success"><?= h($flashOk) ?></div>
    <?php endif; ?>
    <?php if ($flashErr): ?>
        <div class="alert alert-danger"><?= h($flashErr) ?></div>
    <?php endif; ?>

    <?php if ($result !== null): ?>
        <?php if ($result['errors']): ?>
            <div class="alert alert-danger">
                <?php foreach ($result['errors'] as $err): ?>
                    <div><?= h($err) ?></div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="alert alert-success">
                <strong><?= $resultType === 's3' ? 'Synchronizace ze S3 dokončena.' : 'Import ze souboru dokončen.' ?></strong>
                <table class="table table-sm table-borderless mb-0 mt-2 w-auto">
                    <tr><td>Nově vloženo</td>  <td class="fw-bold ps-3"><?= $result['inserted'] ?></td></tr>
                    <tr><td>Aktualizováno</td> <td class="fw-bold ps-3"><?= $result['updated'] ?></td></tr>
                    <tr><td>Přeskočeno</td>    <td class="fw-bold ps-3"><?= $result['skipped'] ?></td></tr>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- ── Stav databáze ── -->
    <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold">Stav</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-sm-6">
                    <span class="text-muted small d-block">Vozidla v databázi</span>
                    <strong class="fs-5"><?= number_format($countInDb, 0, ',', ' ') ?></strong>
                </div>
                <div class="col-sm-6">
                    <span class="text-muted small d-block">Poslední synchronizace ze S3</span>
                    <?php if ($lastSync): ?>
                        <strong><?= h(toLocalTime($lastSync)) ?></strong>
                        <?php if ($lastSyncCount): ?>
                            <span class="text-muted small ms-1">(<?= h($lastSyncCount) ?> záznamů)</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-muted">zatím nebyla spuštěna</span>
                    <?php endif; ?>
                </div>
                <div class="col-sm-6">
                    <span class="text-muted small d-block">Soubor na S3 — datum změny</span>
                    <?php if ($s3FileLastModified): ?>
                        <strong><?= h($s3FileLastModified) ?></strong>
                    <?php else: ?>
                        <span class="text-muted">zatím nezjišťováno</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Synchronizace ze S3 ── -->
    <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold">Synchronizace ze S3</div>
        <div class="card-body">
            <p class="text-muted small mb-3">
                Stáhne aktuální CSV z Amazon S3 a aktualizuje databázi vozidel.
                Probíhá automaticky přes cron — nebo spusťte ručně.
            </p>

            <?php if ($syncKey): ?>
                <div class="mb-3">
                    <span class="text-muted small d-block mb-1">URL pro cron (volat každé 3 hodiny — synchronizuje vozidla i objednávky):</span>
                    <code class="d-block p-2 bg-light rounded small user-select-all">
                        <?= h(APP_URL) ?>/api/sync-vehicles.php?key=<?= h($syncKey) ?>
                    </code>
                </div>
            <?php endif; ?>

            <form method="post" id="syncForm">
                <input type="hidden" name="csrf" value="<?= h(arrStr($_SESSION, 'csrf_token')) ?>">
                <input type="hidden" name="action" value="sync_s3">
                <button type="submit" id="syncBtn" class="btn btn-success"
                    <?= !$s3Configured ? 'disabled title="Nejprve vyplňte S3 nastavení"' : '' ?>>
                    <i class="bi bi-cloud-download me-1"></i>Synchronizovat ze S3
                </button>
                <span id="syncStatus" class="text-muted small ms-2">Může trvat několik sekund.</span>
            </form>

            <div id="syncProgress" class="mt-3 d-none">
                <div class="progress" style="height:6px">
                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-success w-100"></div>
                </div>
                <p class="text-muted small mt-2 mb-0">Probíhá stahování a import, čekejte prosím…</p>
            </div>
        </div>
    </div>

    <!-- ── Nastavení Amazon S3 ── -->
    <?php if ($s3Unlocked): ?>
    <!-- STAV 3: Odemčeno — editovatelný formulář -->
    <div class="card shadow-sm mb-4 border-warning">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
            <span>Nastavení Amazon S3 <span class="text-muted fw-normal small">(společné pro vozidla i objednávky)</span> <span class="badge bg-warning text-dark ms-2"><i class="bi bi-unlock-fill me-1"></i>Odemčeno</span></span>
            <form method="post" class="m-0">
                <input type="hidden" name="csrf" value="<?= h(arrStr($_SESSION, 'csrf_token')) ?>">
                <input type="hidden" name="action" value="cancel_s3_edit">
                <button type="submit" class="btn btn-sm btn-outline-secondary">Zrušit</button>
            </form>
        </div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="csrf" value="<?= h(arrStr($_SESSION, 'csrf_token')) ?>">
                <input type="hidden" name="action" value="save_s3">
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label class="form-label small">Region</label>
                        <input type="text" class="form-control form-control-sm" name="s3_region"
                               value="<?= h($s3Region) ?>" placeholder="eu-central-1">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small">Bucket</label>
                        <input type="text" class="form-control form-control-sm" name="s3_bucket"
                               value="<?= h($s3Bucket) ?>" placeholder="muj-bucket">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small">Cesta k souboru vozidel</label>
                        <input type="text" class="form-control form-control-sm" name="s3_object_key"
                               value="<?= h($s3ObjectKey) ?>" placeholder="zmenynv/export-spz.csv">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small">Cesta k souboru objednávek (objednáno)</label>
                        <input type="text" class="form-control form-control-sm" name="s3_orders_object_key"
                               value="<?= h($s3OrdersKey) ?>" placeholder="zmenynv/planovac-objednano.csv">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small">Cesta k souboru objednávek (příjem)</label>
                        <input type="text" class="form-control form-control-sm" name="s3_prijem_object_key"
                               value="<?= h($s3PrijemKey) ?>" placeholder="zmenynv/planovac-prijem.csv">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small">AWS Access Key ID</label>
                        <input type="text" class="form-control form-control-sm" name="s3_access_key_id"
                               value="<?= h($s3KeyId) ?>" placeholder="AKIA…">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small">AWS Secret Access Key</label>
                        <input type="password" class="form-control form-control-sm" name="s3_secret_access_key"
                               value="<?= h($s3Secret) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label small">
                            Klíč pro cron endpoint
                            <span class="text-muted">(libovolný tajný řetězec, min. 20 znaků)</span>
                        </label>
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control form-control-sm font-monospace"
                                   name="vehicles_sync_key" id="syncKeyInput"
                                   value="<?= h($syncKey) ?>" placeholder="vygenerujte nebo zadejte…">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="genKeyBtn">
                                Generovat
                            </button>
                        </div>
                    </div>
                </div>
                <div class="mt-3">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-floppy me-1"></i>Uložit a zamknout
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php elseif ($s3CodePending): ?>
    <!-- STAV 2: Čeká na ověřovací kód -->
    <div class="card shadow-sm mb-4 border-info">
        <div class="card-header fw-semibold">
            Nastavení Amazon S3 <span class="badge bg-info text-dark ms-2"><i class="bi bi-envelope me-1"></i>Čeká na kód</span>
        </div>
        <div class="card-body">
            <p class="mb-3">Na váš e-mail byl odeslán 6místný ověřovací kód. Zadejte jej pro odemčení nastavení.</p>
            <form method="post" class="d-flex gap-2 align-items-end flex-wrap">
                <input type="hidden" name="csrf" value="<?= h(arrStr($_SESSION, 'csrf_token')) ?>">
                <input type="hidden" name="action" value="verify_s3_code">
                <div>
                    <label class="form-label small mb-1">Ověřovací kód</label>
                    <input type="text" name="s3_code" class="form-control form-control-sm font-monospace"
                           maxlength="6" pattern="[0-9]{6}" placeholder="123456"
                           autocomplete="one-time-code" autofocus style="width:120px">
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Ověřit</button>
            </form>
            <form method="post" class="mt-2">
                <input type="hidden" name="csrf" value="<?= h(arrStr($_SESSION, 'csrf_token')) ?>">
                <input type="hidden" name="action" value="cancel_s3_edit">
                <button type="submit" class="btn btn-link btn-sm text-muted p-0">Zrušit</button>
            </form>
        </div>
    </div>

    <?php else: ?>
    <!-- STAV 1: Uzamčeno — jen pro čtení -->
    <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
            <span>Nastavení Amazon S3 <span class="text-muted fw-normal small">(společné pro vozidla i objednávky)</span> <i class="bi bi-lock-fill text-muted ms-2 small"></i></span>
            <button type="button" class="btn btn-sm btn-outline-secondary"
                    data-bs-toggle="modal" data-bs-target="#confirmS3EditModal">
                <i class="bi bi-pencil me-1"></i>Upravit
            </button>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-sm-6">
                    <span class="text-muted small d-block">Region</span>
                    <strong><?= $s3Region !== '' ? h($s3Region) : '<span class="text-muted">—</span>' ?></strong>
                </div>
                <div class="col-sm-6">
                    <span class="text-muted small d-block">Bucket</span>
                    <strong><?= $s3Bucket !== '' ? h($s3Bucket) : '<span class="text-muted">—</span>' ?></strong>
                </div>
                <div class="col-sm-6">
                    <span class="text-muted small d-block">Cesta k souboru vozidel</span>
                    <strong><?= $s3ObjectKey !== '' ? h($s3ObjectKey) : '<span class="text-muted">—</span>' ?></strong>
                </div>
                <div class="col-sm-6">
                    <span class="text-muted small d-block">Cesta k souboru objednávek (objednáno)</span>
                    <strong><?= $s3OrdersKey !== '' ? h($s3OrdersKey) : '<span class="text-muted">—</span>' ?></strong>
                </div>
                <div class="col-sm-6">
                    <span class="text-muted small d-block">Cesta k souboru objednávek (příjem)</span>
                    <strong><?= $s3PrijemKey !== '' ? h($s3PrijemKey) : '<span class="text-muted">—</span>' ?></strong>
                </div>
                <div class="col-sm-6">
                    <span class="text-muted small d-block">AWS Access Key ID</span>
                    <strong><?= $s3KeyId !== '' ? h($s3KeyId) : '<span class="text-muted">—</span>' ?></strong>
                </div>
                <div class="col-sm-6">
                    <span class="text-muted small d-block">AWS Secret Access Key</span>
                    <strong><?= $s3Secret !== '' ? '••••••••••••••••' : '<span class="text-muted">—</span>' ?></strong>
                </div>
                <div class="col-12">
                    <span class="text-muted small d-block">Klíč pro cron endpoint</span>
                    <strong><?= $syncKey !== '' ? '••••••••••••••••' : '<span class="text-muted">—</span>' ?></strong>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Modal: potvrzení žádosti o editaci S3 -->
    <div class="modal fade" id="confirmS3EditModal" tabindex="-1" aria-labelledby="confirmS3EditLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="confirmS3EditLabel">
                        <i class="bi bi-shield-lock me-2"></i>Upravit nastavení Amazon S3
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Nastavení Amazon S3 obsahuje přístupové klíče ke cloudovému úložišti. Jejich úprava je citlivá operace.</p>
                    <p>Po potvrzení bude na váš e-mail odeslán <strong>jednorázový ověřovací kód</strong>. Teprve po jeho zadání se sekce odemkne k editaci.</p>
                    <p class="mb-0 text-muted small">Kód je platný 10 minut.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zrušit</button>
                    <form method="post" class="m-0">
                        <input type="hidden" name="csrf" value="<?= h(arrStr($_SESSION, 'csrf_token')) ?>">
                        <input type="hidden" name="action" value="request_s3_code">
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-envelope me-1"></i>Odeslat ověřovací kód
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Protokol synchronizace ── -->
    <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold">Protokol synchronizace <span class="text-muted fw-normal small">(posledních 25)</span></div>
        <?php if (!$syncLog): ?>
            <div class="card-body text-muted small">Zatím žádné záznamy.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm mb-0 admin-table">
                <thead>
                    <tr>
                        <th>Čas (Praha)</th>
                        <th>Zdroj</th>
                        <th>Výsledek</th>
                        <th class="text-end">Vloženo</th>
                        <th class="text-end">Aktualiz.</th>
                        <th class="text-end">Přeskočeno</th>
                        <th>Detail</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($syncLog as $entry): ?>
                    <?php
                        $res     = arrStr($entry, 'result');
                        $source  = arrStr($entry, 'source');
                        $ts      = arrStr($entry, 'ts');
                        $tsLocal = $ts !== '' ? toLocalTime($ts) : '—';
                        [$badgeClass, $badgeLabel] = match ($res) {
                            'synced'  => ['bg-success', 'Importováno'],
                            'skipped' => ['bg-secondary', 'Přeskočeno'],
                            default   => ['bg-danger', 'Chyba'],
                        };
                    ?>
                    <tr>
                        <td class="text-nowrap small"><?= h($tsLocal) ?></td>
                        <td><span class="badge bg-light text-dark border"><?= $source === 'manual' ? 'ruční' : 'cron' ?></span></td>
                        <td><span class="badge <?= $badgeClass ?>"><?= $badgeLabel ?></span></td>
                        <td class="text-end"><?= $res === 'synced' ? arrInt($entry, 'inserted', 0) : '—' ?></td>
                        <td class="text-end"><?= $res === 'synced' ? arrInt($entry, 'updated', 0) : '—' ?></td>
                        <td class="text-end"><?= $res === 'synced' ? arrInt($entry, 'skipped_rows', 0) : '—' ?></td>
                        <td class="small text-muted"><?= $res === 'error' ? h(arrStr($entry, 'message')) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Jednorázový import ze souboru ── -->
    <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold">Jednorázový import ze souboru</div>
        <div class="card-body">
            <p class="text-muted small mb-3">
                Načte soubor <code>dataupload/export-spz.csv</code> uložený přímo na serveru
                (UTF-8 nebo Windows-1250, oddělovač <code>;</code>).
            </p>
            <div class="mb-3">
                <span class="text-muted small">Soubor:</span>
                <?php if (is_file($csvPath)): ?>
                    <strong class="ms-1 text-success">nalezen</strong>
                    <span class="text-muted small ms-1">(<?= number_format((int) filesize($csvPath) / 1024, 0, ',', ' ') ?> kB)</span>
                <?php else: ?>
                    <strong class="ms-1 text-danger">nenalezen</strong>
                <?php endif; ?>
            </div>
            <form method="post" id="importForm">
                <input type="hidden" name="csrf" value="<?= h(arrStr($_SESSION, 'csrf_token')) ?>">
                <input type="hidden" name="action" value="import">
                <button type="submit" id="importBtn" class="btn btn-secondary btn-sm"
                    <?= !is_file($csvPath) ? 'disabled' : '' ?>>
                    Spustit import ze souboru
                </button>
                <span id="importStatus" class="text-muted small ms-2">Může trvat několik sekund.</span>
            </form>
            <div id="importProgress" class="mt-3 d-none">
                <div class="progress" style="height:6px">
                    <div class="progress-bar progress-bar-striped progress-bar-animated w-100"></div>
                </div>
                <p class="text-muted small mt-2 mb-0">Probíhá import, čekejte prosím…</p>
            </div>
        </div>
    </div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('syncForm').addEventListener('submit', function () {
    var btn = document.getElementById('syncBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Synchronizuji…';
    document.getElementById('syncStatus').classList.add('d-none');
    document.getElementById('syncProgress').classList.remove('d-none');
});

document.getElementById('importForm').addEventListener('submit', function () {
    var btn = document.getElementById('importBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Importuji…';
    document.getElementById('importStatus').classList.add('d-none');
    document.getElementById('importProgress').classList.remove('d-none');
});

<?php if ($s3Unlocked): ?>
document.getElementById('genKeyBtn').addEventListener('click', function () {
    var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz0123456789';
    var key = '';
    var arr = new Uint8Array(32);
    crypto.getRandomValues(arr);
    arr.forEach(function (b) { key += chars[b % chars.length]; });
    document.getElementById('syncKeyInput').value = key;
});
<?php endif; ?>
</script>
</body>
</html>
