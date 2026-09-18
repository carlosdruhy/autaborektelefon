<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Cron endpoint: jedním voláním synchronizuje vozidla (tel_vehicles)
// i objednávky do servisu (tel_service_orders) ze S3.
// Autentizace: sdílený klíč v URL (?key=...)
$syncKey = getSettingStr('vehicles_sync_key');
if ($syncKey === '' || arrStr($_GET, 'key') !== $syncKey) {
    jsonErr('Neplatný klíč', 403);
}

set_time_limit(240);

$vehicles = syncVehiclesFromS3('cron');

// Objednávky (planovac-objednano.csv + planovac-prijem.csv); soubory bez nastavené cesty se přeskočí
$orders = syncAllServiceOrdersFromS3('cron');

$data = ['vehicles' => $vehicles, 'orders' => $orders];

$errors = [];
if (!in_array($vehicles['status'], ['synced', 'skipped'], true)) {
    $errors[] = 'Vozidla: ' . $vehicles['message'];
}
foreach ($orders as $file => $o) {
    if (!in_array($o['status'], ['synced', 'skipped', 'not_configured'], true)) {
        $errors[] = 'Objednávky (' . $file . '): ' . $o['message'];
    }
}

if ($errors !== []) {
    jsonErr(implode(' | ', $errors), 500, ['data' => $data]);
}

jsonOk($data);
