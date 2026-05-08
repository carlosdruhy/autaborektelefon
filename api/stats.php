<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

startSecureSession();
requireLogin();
checkSessionTimeout();
touchSession();
requireAdmin();

$view = $_GET['view'] ?? '';
$from = $_GET['from'] ?? date('Y-m-01');  // začátek aktuálního měsíce
$to   = $_GET['to']   ?? date('Y-m-d');

// Převod na UTC rozsah (celý den v Prague čase → UTC)
$fromUtc = (new DateTime($from . ' 00:00:00', new DateTimeZone('Europe/Prague')))
    ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$toUtc   = (new DateTime($to . ' 23:59:59', new DateTimeZone('Europe/Prague')))
    ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

switch ($view) {
    case 'by_technician':
        handleByTechnician($fromUtc, $toUtc);
        break;
    case 'by_age':
        handleByAge($fromUtc, $toUtc);
        break;
    default:
        jsonErr('Neznámý pohled. Použijte: by_technician, by_age');
}

function handleByTechnician(string $from, string $to): never
{
    $stmt = getDB()->prepare(
        'SELECT u.name,
                COUNT(*) AS total_resolved,
                ROUND(AVG(TIMESTAMPDIFF(MINUTE, r.created_at, r.resolved_at))) AS avg_minutes,
                SUM(CASE WHEN r.status = \'reopened\' THEN 1 ELSE 0 END) AS reopened_count
         FROM tel_requests r
         JOIN tel_users u ON r.assigned_to_id = u.id
         WHERE r.status = \'resolved\'
           AND r.deleted_at IS NULL
           AND r.resolved_at BETWEEN ? AND ?
         GROUP BY u.id, u.name
         ORDER BY total_resolved DESC'
    );
    $stmt->execute([$from, $to]);
    jsonOk($stmt->fetchAll());
}

function handleByAge(string $from, string $to): never
{
    $settings = getSettings();
    $t1 = (int)($settings['color_level_1'] ?? 15);
    $t2 = (int)($settings['color_level_2'] ?? 30);
    $t3 = (int)($settings['color_level_3'] ?? 60);
    $t4 = (int)($settings['color_level_4'] ?? 120);

    $stmt = getDB()->prepare(
        'SELECT TIMESTAMPDIFF(MINUTE, created_at, resolved_at) AS minutes_to_resolve
         FROM tel_requests
         WHERE status = \'resolved\'
           AND deleted_at IS NULL
           AND resolved_at BETWEEN ? AND ?'
    );
    $stmt->execute([$from, $to]);
    $rows = $stmt->fetchAll();

    $buckets = [
        "0–{$t1} min"       => 0,
        "{$t1}–{$t2} min"   => 0,
        "{$t2}–{$t3} min"   => 0,
        "{$t3}–{$t4} min"   => 0,
        "{$t4}+ min"        => 0,
    ];
    $keys = array_keys($buckets);

    foreach ($rows as $r) {
        $m = (int)$r['minutes_to_resolve'];
        if ($m < $t1) {
            $buckets[$keys[0]]++;
        } elseif ($m < $t2) {
            $buckets[$keys[1]]++;
        } elseif ($m < $t3) {
            $buckets[$keys[2]]++;
        } elseif ($m < $t4) {
            $buckets[$keys[3]]++;
        } else {
            $buckets[$keys[4]]++;
        }
    }

    $result = [];
    foreach ($buckets as $label => $count) {
        $result[] = ['label' => $label, 'count' => $count];
    }

    jsonOk($result);
}
