<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

startSecureSession();
requireAdmin();
checkSessionTimeout();
touchSession();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

switch ($action) {
    case 'list':
        handleList();
    case 'save':
        if ($method !== 'POST') jsonErr('Metoda není povolena', 405);
        verifyCsrf();
        handleSave();
    case 'toggle_active':
        if ($method !== 'POST') jsonErr('Metoda není povolena', 405);
        verifyCsrf();
        handleToggleActive();
    default:
        jsonErr('Neznámá akce', 400);
}

function handleList(): never
{
    $stmt = getDB()->query(
        "SELECT b.id, b.code, b.name, b.dms_center_code, b.is_active, b.sort_order,
                (SELECT COUNT(*) FROM tel_user_branches ub
                   JOIN tel_users u ON u.id = ub.user_id AND u.is_active = 1
                  WHERE ub.branch_id = b.id) AS user_count,
                (SELECT COUNT(*) FROM tel_requests r
                  WHERE r.branch_id = b.id AND r.status != 'resolved' AND r.deleted_at IS NULL) AS open_count
         FROM tel_branches b
         ORDER BY b.sort_order ASC, b.name ASC"
    ) ?: throw new \RuntimeException('Query failed');
    jsonOk(pdoFetchAll($stmt));
}

/** Založení (id = 0) nebo úprava pobočky. */
function handleSave(): never
{
    $body      = getPostedJson();
    $id        = arrInt($body, 'id');
    $code      = strtoupper(trim(arrStr($body, 'code')));
    $name      = trim(arrStr($body, 'name'));
    $center    = trim(arrStr($body, 'dms_center_code'));
    $sortOrder = arrInt($body, 'sort_order');

    if ($code === '' || mb_strlen($code) > 20) jsonErr('Kód je povinný (nejvýše 20 znaků)');
    if (preg_match('/^[A-Z0-9_-]+$/', $code) !== 1) jsonErr('Kód smí obsahovat jen písmena bez diakritiky, číslice, - a _');
    if ($name === '' || mb_strlen($name) > 100) jsonErr('Název je povinný (nejvýše 100 znaků)');
    if (mb_strlen($center) > 20) jsonErr('Kód střediska může mít nejvýše 20 znaků');

    $db = getDB();

    $check = $db->prepare('SELECT id FROM tel_branches WHERE code = ? AND id != ?');
    $check->execute([$code, $id]);
    if (pdoFetch($check)) jsonErr('Pobočka s tímto kódem už existuje');

    if ($id > 0) {
        $exists = $db->prepare('SELECT id FROM tel_branches WHERE id = ?');
        $exists->execute([$id]);
        if (!pdoFetch($exists)) jsonErr('Pobočka nenalezena', 404);

        $db->prepare('UPDATE tel_branches SET code = ?, name = ?, dms_center_code = ?, sort_order = ? WHERE id = ?')
           ->execute([$code, $name, $center !== '' ? $center : null, $sortOrder, $id]);
        jsonOk(['id' => $id]);
    }

    $db->prepare(
        'INSERT INTO tel_branches (code, name, dms_center_code, is_active, sort_order, created_at)
         VALUES (?, ?, ?, 1, ?, ?)'
    )->execute([$code, $name, $center !== '' ? $center : null, $sortOrder, nowUtc()]);
    jsonOk(['id' => (int) $db->lastInsertId()]);
}

/** Deaktivace / aktivace. Pobočku s nevyřízenými požadavky nelze deaktivovat (PRD 4.10). */
function handleToggleActive(): never
{
    $id = arrInt(getPostedJson(), 'id');
    if ($id <= 0) jsonErr('Chybí ID');

    $db   = getDB();
    $stmt = $db->prepare('SELECT is_active FROM tel_branches WHERE id = ?');
    $stmt->execute([$id]);
    $branch = pdoFetch($stmt);
    if (!$branch) jsonErr('Pobočka nenalezena', 404);

    $newState = arrInt($branch, 'is_active') === 1 ? 0 : 1;

    if ($newState === 0) {
        $open = $db->prepare(
            "SELECT COUNT(*) FROM tel_requests WHERE branch_id = ? AND status != 'resolved' AND deleted_at IS NULL"
        );
        $open->execute([$id]);
        $openCount = (int) $open->fetchColumn();
        if ($openCount > 0) {
            jsonErr("Pobočka má {$openCount} nevyřízených požadavků — nejdřív je přeřaďte nebo vyřiďte.");
        }
    }

    $db->prepare('UPDATE tel_branches SET is_active = ? WHERE id = ?')->execute([$newState, $id]);
    jsonOk(['id' => $id, 'is_active' => $newState]);
}
