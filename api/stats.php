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

$view = arrStr($_GET, 'view');
$from = arrStr($_GET, 'from', date('Y-m-01'));
$to   = arrStr($_GET, 'to', date('Y-m-d'));

// Převod na UTC rozsah (celý den v Prague čase → UTC)
$fromUtc = (new DateTime($from . ' 00:00:00', new DateTimeZone('Europe/Prague')))
    ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$toUtc   = (new DateTime($to . ' 23:59:59', new DateTimeZone('Europe/Prague')))
    ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

// Volitelný filtr pobočky (0 = všechny). Požadavek se počítá pobočce, kde je aktuálně (PRD 4.3).
$branch = arrInt($_GET, 'branch');

switch ($view) {
    case 'by_technician':
        handleByTechnician($fromUtc, $toUtc, $branch);
    case 'by_age':
        handleByAge($fromUtc, $toUtc, $branch);
    case 'by_branch':
        handleByBranch($fromUtc, $toUtc);
    default:
        jsonErr('Neznámý pohled. Použijte: by_technician, by_age, by_branch');
}

function handleByTechnician(string $from, string $to, int $branch): never
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
           AND (? = 0 OR r.branch_id = ?)
         GROUP BY u.id, u.name
         ORDER BY total_resolved DESC'
    );
    $stmt->execute([$from, $to, $branch, $branch]);
    jsonOk($stmt->fetchAll());
}

/**
 * Podle poboček: přijaté a vyřízené požadavky, průměrná doba vyřízení, přeřazení odjinud a jinam.
 */
function handleByBranch(string $from, string $to): never
{
    $stmt = getDB()->prepare(
        "SELECT b.code, b.name, b.is_active,
                (SELECT COUNT(*) FROM tel_requests r
                  WHERE r.branch_id = b.id AND r.deleted_at IS NULL
                    AND r.created_at BETWEEN ? AND ?) AS created_count,
                (SELECT COUNT(*) FROM tel_requests r
                  WHERE r.branch_id = b.id AND r.deleted_at IS NULL AND r.status = 'resolved'
                    AND r.resolved_at BETWEEN ? AND ?) AS resolved_count,
                (SELECT ROUND(AVG(TIMESTAMPDIFF(MINUTE, r.created_at, r.resolved_at))) FROM tel_requests r
                  WHERE r.branch_id = b.id AND r.deleted_at IS NULL AND r.status = 'resolved'
                    AND r.resolved_at BETWEEN ? AND ?) AS avg_minutes,
                (SELECT COUNT(*) FROM tel_request_history h
                  WHERE h.action = 'branch_changed' AND CAST(h.new_value AS UNSIGNED) = b.id
                    AND h.created_at BETWEEN ? AND ?) AS moved_in,
                (SELECT COUNT(*) FROM tel_request_history h
                  WHERE h.action = 'branch_changed' AND CAST(h.old_value AS UNSIGNED) = b.id
                    AND h.created_at BETWEEN ? AND ?) AS moved_out
         FROM tel_branches b
         ORDER BY b.sort_order ASC, b.name ASC"
    );
    $stmt->execute([$from, $to, $from, $to, $from, $to, $from, $to, $from, $to]);
    jsonOk($stmt->fetchAll());
}

function handleByAge(string $from, string $to, int $branch): never
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
           AND resolved_at BETWEEN ? AND ?
           AND (? = 0 OR branch_id = ?)'
    );
    $stmt->execute([$from, $to, $branch, $branch]);
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
