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

$db = getDB();

// ─── Zpracování formulářů ─────────────────────────────────────────────────────
$result   = [];
$flashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(arrStr($_SESSION, 'csrf_token'), arrStr($_POST, 'csrf'))) {
        http_response_code(403);
        die('Neplatný CSRF token.');
    }

    // Ruční synchronizace ze S3 (ignoruje ETag — stahuje vždy)
    if (arrStr($_POST, 'action') === 'sync_s3') {
        set_time_limit(120);
        $results = syncAllServiceOrdersFromS3('manual');
        $errs    = [];
        foreach ($results as $file => $r) {
            if ($r['status'] === 'synced') {
                $result[$file] = $r;
            } elseif ($r['status'] !== 'not_configured') {
                $errs[] = SERVICE_ORDER_FILES[$file]['file'] . ': ' . $r['message'];
            }
        }
        if ($errs !== []) {
            $flashErr = 'Synchronizace selhala — ' . implode(' | ', $errs);
        }
        if ($result === [] && $errs === []) {
            $flashErr = 'S3 není nakonfigurováno. Vyplňte cesty k souborům objednávek na stránce Vozidla.';
        }
    }
}

// ─── Statistiky z DB (po případném importu) ───────────────────────────────────
$countStmt = $db->query('SELECT COUNT(*) FROM tel_service_orders');
$countInDb = $countStmt !== false ? (int) $countStmt->fetchColumn() : 0;

$upcomingStmt = $db->prepare('SELECT COUNT(*) FROM tel_service_orders WHERE scheduled_at >= ?');
$upcomingStmt->execute([nowUtc()]);
$countUpcoming = (int) $upcomingStmt->fetchColumn();

$lastSync           = getSettingStr('orders_last_sync');
$lastSyncCount      = getSettingStr('orders_last_sync_count');
$syncKey            = getSettingStr('vehicles_sync_key');

$anyFileConfigured = false;
foreach (SERVICE_ORDER_FILES as $cfg) {
    if (getSettingStr($cfg['object_key']) !== '') {
        $anyFileConfigured = true;
    }
}
$s3Configured = getSettingStr('s3_access_key_id') !== ''
    && getSettingStr('s3_secret_access_key') !== ''
    && getSettingStr('s3_region') !== ''
    && getSettingStr('s3_bucket') !== ''
    && $anyFileConfigured;

$syncLogRaw     = getSettingStr('orders_sync_log');
$syncLogDecoded = $syncLogRaw !== '' ? json_decode($syncLogRaw, true) : null;
$syncLog        = [];
if (is_array($syncLogDecoded)) {
    foreach ($syncLogDecoded as $item) {
        if (is_array($item)) {
            $syncLog[] = $item;
        }
    }
}

// Náhled nadcházejících objednávek (od dnešního dne v Praze), s modelem z evidence vozidel
$listStmt = $db->prepare('
    SELECT o.scheduled_at, o.spz_original, o.vin, o.client_name, o.source, o.center_code, v.model
    FROM tel_service_orders o
    LEFT JOIN tel_vehicles v ON v.spz_normalized = o.spz_normalized
    WHERE o.scheduled_at >= ?
    ORDER BY o.scheduled_at ASC, o.source ASC, o.id ASC
    LIMIT 500
');
$listStmt->execute([pragueTodayStartUtc()]);
$orders = pdoFetchAll($listStmt);
$centerLabels = getCenterLabels($db);

?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Objednávky – Admin – <?= h(APP_NAME) ?></title>
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
        <a href="import-vehicles.php" class="btn btn-sm btn-outline-light">Vozidla</a>
        <a href="orders.php" class="btn btn-sm btn-outline-light active">Objednávky</a>
        <a href="../logout.php" class="btn btn-sm btn-outline-light">Odhlásit</a>
    </div>
</nav>

<div class="container py-4" style="max-width:900px">
    <h1 class="h4 mb-4">Objednávky do servisu</h1>

    <?php if ($flashErr): ?>
        <div class="alert alert-danger"><?= h($flashErr) ?></div>
    <?php endif; ?>

    <?php if ($result !== []): ?>
        <div class="alert alert-success">
            <strong>Synchronizace ze S3 dokončena.</strong>
            <table class="table table-sm table-borderless mb-0 mt-2 w-auto">
                <tr><th></th><th class="ps-3">Načteno</th><th class="ps-3">Přeskočeno</th></tr>
                <?php foreach ($result as $file => $r): ?>
                <tr>
                    <td><?= h(SERVICE_ORDER_FILES[$file]['file']) ?></td>
                    <td class="fw-bold ps-3"><?= $r['imported'] ?></td>
                    <td class="fw-bold ps-3"><?= $r['skipped'] ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>

    <!-- ── Stav ── -->
    <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold">Stav</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-sm-6">
                    <span class="text-muted small d-block">Objednávky v databázi</span>
                    <strong class="fs-5"><?= number_format($countInDb, 0, ',', ' ') ?></strong>
                    <span class="text-muted small ms-1">(z toho nadcházející: <?= number_format($countUpcoming, 0, ',', ' ') ?>)</span>
                </div>
                <div class="col-sm-6">
                    <span class="text-muted small d-block">Poslední synchronizace ze S3</span>
                    <?php if ($lastSync): ?>
                        <strong><?= h(toLocalTime($lastSync)) ?></strong>
                        <span class="text-muted small ms-1">(<?= h($lastSyncCount) ?> záznamů)</span>
                    <?php else: ?>
                        <span class="text-muted">zatím nebyla spuštěna</span>
                    <?php endif; ?>
                </div>
                <?php foreach (SERVICE_ORDER_FILES as $cfg): ?>
                    <?php $objKey = getSettingStr($cfg['object_key']); $lastMod = getSettingStr($cfg['last_modified']); ?>
                    <div class="col-sm-6">
                        <span class="text-muted small d-block">Soubor na S3 — <?= h($cfg['label']) ?></span>
                        <strong><?= $objKey !== '' ? h($objKey) : '<span class="text-muted">— (nastavte na stránce Vozidla)</span>' ?></strong>
                        <?php if ($lastMod): ?>
                            <span class="text-muted small d-block">změněn: <?= h($lastMod) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ── Synchronizace ze S3 ── -->
    <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold">Synchronizace ze S3</div>
        <div class="card-body">
            <p class="text-muted small mb-3">
                Stáhne aktuální <code>planovac-objednano.csv</code> a <code>planovac-prijem.csv</code> z Amazon S3;
                každý soubor nahradí v tabulce objednávek řádky svého zdroje.
                Cron synchronizuje objednávky společně s vozidly jedním voláním.
                VIN vozidla se skládá ze sloupců <code>fabkod</code> + <code>vinkod</code>.
                Přístupové údaje k S3 se nastavují na stránce <a href="import-vehicles.php">Vozidla</a>.
            </p>

            <?php if ($syncKey): ?>
                <div class="mb-3">
                    <span class="text-muted small d-block mb-1">URL pro cron (společná pro vozidla i objednávky, volat každé 3 hodiny):</span>
                    <code class="d-block p-2 bg-light rounded small user-select-all">
                        <?= h(APP_URL) ?>/api/sync-vehicles.php?key=<?= h($syncKey) ?>
                    </code>
                </div>
            <?php endif; ?>

            <form method="post" id="syncForm">
                <input type="hidden" name="csrf" value="<?= h(arrStr($_SESSION, 'csrf_token')) ?>">
                <input type="hidden" name="action" value="sync_s3">
                <button type="submit" id="syncBtn" class="btn btn-success"
                    <?= !$s3Configured ? 'disabled title="Nejprve vyplňte S3 nastavení na stránce Vozidla"' : '' ?>>
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
                        <th>Soubor</th>
                        <th>Výsledek</th>
                        <th class="text-end">Načteno</th>
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
                            'skipped' => ['bg-secondary', 'Beze změny'],
                            default   => ['bg-danger', 'Chyba'],
                        };
                    ?>
                    <tr>
                        <td class="text-nowrap small"><?= h($tsLocal) ?></td>
                        <td><span class="badge bg-light text-dark border"><?= $source === 'manual' ? 'ruční' : 'cron' ?></span></td>
                        <td class="small"><?= h(SERVICE_ORDER_FILES[arrStr($entry, 'file', 'objednano')]['label'] ?? '') ?></td>
                        <td><span class="badge <?= $badgeClass ?>"><?= $badgeLabel ?></span></td>
                        <td class="text-end"><?= $res === 'synced' ? arrInt($entry, 'imported', 0) : '—' ?></td>
                        <td class="text-end"><?= $res === 'synced' ? arrInt($entry, 'skipped_rows', 0) : '—' ?></td>
                        <td class="small text-muted"><?= $res === 'error' ? h(arrStr($entry, 'message')) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Nadcházející objednávky ── -->
    <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold">
            Nadcházející objednávky
            <span class="text-muted fw-normal small">(od dneška, max. 500)</span>
        </div>
        <?php if (!$orders): ?>
            <div class="card-body text-muted small">Žádné nadcházející objednávky.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 admin-table">
                <thead>
                    <tr>
                        <th>Termín (Praha)</th>
                        <th>SPZ</th>
                        <th>Vozidlo</th>
                        <th>VIN</th>
                        <th>Klient</th>
                        <th>Středisko</th>
                        <th>Zdroj</th>
                    </tr>
                </thead>
                <tbody>
                <?php $prevDay = ''; ?>
                <?php foreach ($orders as $o): ?>
                    <?php
                        $local = toLocalTime(arrStr($o, 'scheduled_at'));
                        $day   = substr($local, 0, 10);
                    ?>
                    <?php if ($day !== $prevDay): ?>
                        <tr class="table-light"><td colspan="7" class="fw-semibold small"><?= h($day) ?></td></tr>
                        <?php $prevDay = $day; ?>
                    <?php endif; ?>
                    <tr>
                        <td class="text-nowrap"><?= h(substr($local, 11)) ?></td>
                        <td class="font-monospace"><?= h(arrStrNull($o, 'spz_original') ?? '—') ?></td>
                        <td class="small"><?= h(arrStrNull($o, 'model') ?? '') ?></td>
                        <td class="font-monospace small"><?= h(arrStrNull($o, 'vin') ?? '—') ?></td>
                        <td><?= h(arrStrNull($o, 'client_name') ?? '') ?></td>
                        <?php $center = arrStr($o, 'center_code'); ?>
                        <td class="small"><?= $center !== '' ? h(($centerLabels[$center] ?? '') . ' (' . $center . ')') : '<span class="text-muted">—</span>' ?></td>
                        <td class="small text-muted"><?= h(SERVICE_ORDER_FILES[arrStr($o, 'source')]['label'] ?? arrStr($o, 'source')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
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
</script>
</body>
</html>
