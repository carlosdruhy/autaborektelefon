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

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

switch ($action) {
    case 'list':
        handleList();
    case 'get':
        handleGet();
    case 'create':
        if ($method !== 'POST') {
            jsonErr('Metoda není povolena', 405);
        }
        verifyCsrf();
        handleCreate();
    case 'update':
        if ($method !== 'POST') {
            jsonErr('Metoda není povolena', 405);
        }
        verifyCsrf();
        handleUpdate();
    default:
        jsonErr('Neznámá akce', 400);
}

// ─── GET list ─────────────────────────────────────────────────────────────────

function handleList(): never
{
    $db = getDB();

    $statusFilter = $_GET['status'] ?? '';
    $sort         = ($_GET['sort'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
    $search       = trim(arrStr($_GET, 'search'));

    $params = [];

    if ($statusFilter === 'deleted') {
        if (!isAdmin()) {
            jsonErr('Nedostatečná oprávnění', 403);
        }
        $where = ['r.deleted_at IS NOT NULL'];
    } else {
        $where = ['r.deleted_at IS NULL'];
        if ($statusFilter !== '' && $statusFilter !== 'all') {
            if ($statusFilter === 'mine') {
                $where[]  = 'r.assigned_to_id = ?';
                $params[] = currentUserId();
            } else {
                $where[]  = 'r.status = ?';
                $params[] = $statusFilter;
            }
        } else {
            // Výchozí: aktivní (bez resolved)
            $where[] = "r.status != 'resolved'";
        }
    }

    if ($search !== '') {
        $like     = '%' . $search . '%';
        $where[]  = '(r.spz LIKE ? OR r.client_name LIKE ? OR r.client_phone LIKE ?)';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $whereStr = implode(' AND ', $where);
    $sql = "SELECT r.*,
                   COALESCE(s.sms_count, 0) AS sms_count,
                   u1.name AS created_by_name,
                   u2.name AS assigned_to_name
            FROM tel_requests r
            LEFT JOIN (
                SELECT request_id, COUNT(*) AS sms_count
                FROM tel_sms_queue GROUP BY request_id
            ) s ON s.request_id = r.id
            LEFT JOIN tel_users u1 ON r.created_by     = u1.id
            LEFT JOIN tel_users u2 ON r.assigned_to_id = u2.id
            WHERE $whereStr
            ORDER BY r.created_at $sort";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['age_minutes']  = ageMinutes($row['created_at']);
        $row['created_at_local'] = toLocalTime($row['created_at']);
        $row['updated_at_local'] = toLocalTime($row['updated_at']);
        if ($row['resolved_at']) {
            $row['resolved_at_local'] = toLocalTime($row['resolved_at']);
        }
        if ($row['deleted_at']) {
            $row['deleted_at_local'] = toLocalTime($row['deleted_at']);
        }
        if ($row['assigned_at']) {
            $row['assigned_at_local'] = toLocalTime($row['assigned_at']);
        }
    }
    unset($row);

    jsonOk($rows);
}

// ─── GET single ───────────────────────────────────────────────────────────────

function handleGet(): never
{
    $id = arrInt($_GET, 'id');
    if ($id <= 0) {
        jsonErr('Chybí ID', 400);
    }

    $db = getDB();
    $stmt = $db->prepare(
        'SELECT r.*,
                COALESCE(s.sms_count, 0) AS sms_count,
                u1.name AS created_by_name,
                u2.name AS assigned_to_name
         FROM tel_requests r
         LEFT JOIN (
             SELECT request_id, COUNT(*) AS sms_count
             FROM tel_sms_queue GROUP BY request_id
         ) s ON s.request_id = r.id
         LEFT JOIN tel_users u1 ON r.created_by     = u1.id
         LEFT JOIN tel_users u2 ON r.assigned_to_id = u2.id
         WHERE r.id = ? AND r.deleted_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = pdoFetch($stmt);

    if (!$row) {
        jsonErr('Požadavek nenalezen', 404);
    }

    $row['age_minutes']      = ageMinutes(arrStr($row, 'created_at'));
    $row['created_at_local'] = toLocalTime(arrStr($row, 'created_at'));
    $row['updated_at_local'] = toLocalTime(arrStr($row, 'updated_at'));
    $resolvedAt = arrStrNull($row, 'resolved_at');
    if ($resolvedAt !== null) {
        $row['resolved_at_local'] = toLocalTime($resolvedAt);
    }
    $assignedAt = arrStrNull($row, 'assigned_at');
    if ($assignedAt !== null) {
        $row['assigned_at_local'] = toLocalTime($assignedAt);
    }

    // Historie (posledních 20)
    $hStmt = $db->prepare(
        'SELECT h.*, u.name AS user_name
         FROM tel_request_history h
         JOIN tel_users u ON h.user_id = u.id
         WHERE h.request_id = ?
         ORDER BY h.created_at DESC
         LIMIT 20'
    );
    $hStmt->execute([$id]);
    $history = pdoFetchAll($hStmt);
    foreach ($history as &$h) {
        $h['created_at_local'] = toLocalTime(arrStr($h, 'created_at'));
    }
    unset($h);

    $row['history'] = $history;

    jsonOk($row);
}

// ─── POST create ──────────────────────────────────────────────────────────────

function handleCreate(): never
{
    $body = getPostedJson();

    $spz         = trim(arrStr($body, 'spz'));
    $clientName  = trim(arrStr($body, 'client_name'));
    $clientPhone = trim(arrStr($body, 'client_phone'));
    $clientEmail = trim(arrStr($body, 'client_email'));
    $requestText = trim(arrStr($body, 'request_text'));

    if ($spz === '' || $clientName === '' || $requestText === '') {
        jsonErr('Povinná pole: spz, client_name, request_text');
    }
    if (mb_strlen($requestText) > 2000) {
        jsonErr('Text požadavku překračuje 2 000 znaků');
    }
    if ($clientEmail !== '' && !filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
        jsonErr('Neplatný formát e-mailu');
    }

    $spzNorm = normalizeSpz($spz);
    $now     = nowUtc();
    $userId  = currentUserId();

    $db = getDB();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            'INSERT INTO tel_requests
                (spz, client_name, client_phone, client_email, request_text,
                 status, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, \'new\', ?, ?, ?)'
        );
        $stmt->execute([
            $spzNorm,
            $clientName,
            $clientPhone ?: null,
            $clientEmail ?: null,
            $requestText,
            $userId,
            $now,
            $now,
        ]);
        $newId = (int)$db->lastInsertId();
        logAudit($db, $newId, $userId, 'created');
        $db->commit();

        // Vrátíme nový záznam
        $row = $db->prepare(
            'SELECT r.*, u1.name AS created_by_name, u2.name AS assigned_to_name
             FROM tel_requests r
             LEFT JOIN tel_users u1 ON r.created_by = u1.id
             LEFT JOIN tel_users u2 ON r.assigned_to_id = u2.id
             WHERE r.id = ?'
        );
        $row->execute([$newId]);
        $req = pdoFetch($row) ?: throw new \RuntimeException('Nový záznam nenalezen');
        $req['age_minutes']      = 0;
        $req['created_at_local'] = toLocalTime(arrStr($req, 'created_at'));
        $req['updated_at_local'] = toLocalTime(arrStr($req, 'updated_at'));

        jsonOk($req);
    } catch (Throwable $e) {
        $db->rollBack();
        appLog('create error: ' . $e->getMessage());
        jsonErr('Chyba při ukládání', 500);
    }
}

// ─── POST update ──────────────────────────────────────────────────────────────

function handleUpdate(): never
{
    $body       = getPostedJson();
    $id         = arrInt($body, 'id');
    $expectedAt = trim(arrStr($body, 'expected_updated_at'));
    $actionType = arrStr($body, 'action_type');

    if ($id <= 0) {
        jsonErr('Chybí ID');
    }

    $db = getDB();

    // Načti aktuální záznam
    $stmt = $db->prepare(
        'SELECT r.*, u2.name AS assigned_to_name
         FROM tel_requests r
         LEFT JOIN tel_users u2 ON r.assigned_to_id = u2.id
         WHERE r.id = ? AND r.deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute([$id]);
    $req = pdoFetch($stmt);

    if (!$req) {
        jsonErr('Požadavek nenalezen', 404);
    }

    // Race condition check
    if ($expectedAt !== '' && $req['updated_at'] !== $expectedAt) {
        $editor = arrStr($req, 'assigned_to_name', 'někdo jiný');
        jsonErr(
            "Požadavek byl mezitím upraven uživatelem $editor. Klikněte pro obnovení dat.",
            409,
            ['conflict' => true]
        );
    }

    $userId = currentUserId();
    $now    = nowUtc();

    switch ($actionType) {
        case 'assign':
            handleAssign($db, $req, $userId, $now);
        case 'takeover':
            handleTakeover($db, $req, $userId, $now, $body);
        case 'set_pending':
            handleSetPending($db, $req, $userId, $now, $body);
        case 'resume':
            handleResume($db, $req, $userId, $now);
        case 'resolve':
            handleResolve($db, $req, $userId, $now, $body);
        case 'reopen':
            handleReopen($db, $req, $userId, $now, $body);
        case 'edit_field':
            handleEditField($db, $req, $userId, $now, $body);
        case 'soft_delete':
            handleSoftDelete($db, $req, $userId, $now);
        default:
            jsonErr('Neznámá action_type');
    }
}

// ── assign ────────────────────────────────────────────────────────────────────

/** @param array<string, mixed> $req */
function handleAssign(PDO $db, array $req, int $userId, string $now): never
{
    if (!in_array($req['status'], ['new', 'reopened'], true)) {
        jsonErr('Nelze převzít v aktuálním stavu');
    }

    $reqId = arrInt($req, 'id');
    $db->beginTransaction();
    try {
        $db->prepare(
            'UPDATE tel_requests
             SET status = \'in_progress\', assigned_to_id = ?, assigned_at = ?, updated_at = ?
             WHERE id = ?'
        )->execute([$userId, $now, $now, $reqId]);
        logAudit($db, $reqId, $userId, 'status_change', 'status', arrStr($req, 'status'), 'in_progress');
        logAudit($db, $reqId, $userId, 'assigned', 'assigned_to_id', arrStr($req, 'assigned_to_id'), (string)$userId);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        appLog('assign error: ' . $e->getMessage());
        jsonErr('Chyba při ukládání', 500);
    }

    jsonOk(['id' => $reqId]);
}

// ── takeover ──────────────────────────────────────────────────────────────────

/**
 * @param array<string, mixed> $req
 * @param array<string, mixed> $body
 */
function handleTakeover(PDO $db, array $req, int $userId, string $now, array $body): never
{
    if ($req['status'] !== 'in_progress') {
        jsonErr('Přebrání je možné jen pro stav in_progress');
    }
    if (arrInt($req, 'assigned_to_id') === $userId) {
        jsonErr('Ticket je již váš');
    }

    $reason = trim(arrStr($body, 'takeover_reason'));
    $reqId  = arrInt($req, 'id');

    $db->beginTransaction();
    try {
        $db->prepare(
            'UPDATE tel_requests
             SET assigned_to_id = ?, assigned_at = ?, updated_at = ?
             WHERE id = ?'
        )->execute([$userId, $now, $now, $reqId]);
        logAudit($db, $reqId, $userId, 'takeover', 'assigned_to_id', arrStr($req, 'assigned_to_id'), (string)$userId);
        if ($reason !== '') {
            logAudit($db, $reqId, $userId, 'field_edit', 'takeover_reason', null, $reason);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        appLog('takeover error: ' . $e->getMessage());
        jsonErr('Chyba při ukládání', 500);
    }

    jsonOk(['id' => $reqId]);
}

// ── set_pending ───────────────────────────────────────────────────────────────

/**
 * @param array<string, mixed> $req
 * @param array<string, mixed> $body
 */
function handleSetPending(PDO $db, array $req, int $userId, string $now, array $body): never
{
    if ($req['status'] !== 'in_progress') {
        jsonErr('Čekat lze pouze ze stavu in_progress');
    }

    $reason = trim(arrStr($body, 'pending_reason'));
    if ($reason === '') {
        jsonErr('Důvod čekání je povinný');
    }

    $reqId = arrInt($req, 'id');
    $db->beginTransaction();
    try {
        $db->prepare(
            'UPDATE tel_requests
             SET status = \'pending\', pending_reason = ?, updated_at = ?
             WHERE id = ?'
        )->execute([$reason, $now, $reqId]);
        logAudit($db, $reqId, $userId, 'status_change', 'status', arrStr($req, 'status'), 'pending');
        logAudit($db, $reqId, $userId, 'field_edit', 'pending_reason', arrStrNull($req, 'pending_reason'), $reason);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        appLog('set_pending error: ' . $e->getMessage());
        jsonErr('Chyba při ukládání', 500);
    }

    jsonOk(['id' => $reqId]);
}

// ── resume ────────────────────────────────────────────────────────────────────

/** @param array<string, mixed> $req */
function handleResume(PDO $db, array $req, int $userId, string $now): never
{
    if ($req['status'] !== 'pending') {
        jsonErr('Pokračovat lze pouze ze stavu pending');
    }

    $reqId = arrInt($req, 'id');
    $db->beginTransaction();
    try {
        $db->prepare(
            'UPDATE tel_requests
             SET status = \'in_progress\', assigned_to_id = ?, assigned_at = ?, updated_at = ?
             WHERE id = ?'
        )->execute([$userId, $now, $now, $reqId]);
        logAudit($db, $reqId, $userId, 'status_change', 'status', 'pending', 'in_progress');
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        appLog('resume error: ' . $e->getMessage());
        jsonErr('Chyba při ukládání', 500);
    }

    jsonOk(['id' => $reqId]);
}

// ── resolve ───────────────────────────────────────────────────────────────────

/**
 * @param array<string, mixed> $req
 * @param array<string, mixed> $body
 */
function handleResolve(PDO $db, array $req, int $userId, string $now, array $body): never
{
    if ($req['status'] !== 'in_progress') {
        jsonErr('Uzavřít lze pouze ze stavu in_progress');
    }

    $note = trim(arrStr($body, 'technician_note'));
    if ($note === '') {
        jsonErr('Poznámka k řešení je povinná');
    }

    $reqId = arrInt($req, 'id');
    $db->beginTransaction();
    try {
        $db->prepare(
            'UPDATE tel_requests
             SET status = \'resolved\', technician_note = ?, resolved_at = ?, updated_at = ?
             WHERE id = ?'
        )->execute([$note, $now, $now, $reqId]);
        logAudit($db, $reqId, $userId, 'status_change', 'status', arrStr($req, 'status'), 'resolved');
        logAudit($db, $reqId, $userId, 'field_edit', 'technician_note', arrStrNull($req, 'technician_note'), $note);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        appLog('resolve error: ' . $e->getMessage());
        jsonErr('Chyba při ukládání', 500);
    }

    jsonOk(['id' => $reqId]);
}

// ── reopen ────────────────────────────────────────────────────────────────────

/**
 * @param array<string, mixed> $req
 * @param array<string, mixed> $body
 */
function handleReopen(PDO $db, array $req, int $userId, string $now, array $body): never
{
    if ($req['status'] !== 'resolved') {
        jsonErr('Znovuotevřít lze pouze vyřízený požadavek');
    }
    if (!isAdmin() && !currentUserCanReopen()) {
        jsonErr('Nemáte oprávnění znovuotevřít uzavřený požadavek', 403);
    }

    $reason = trim(arrStr($body, 'reopen_reason'));
    if ($reason === '') {
        jsonErr('Důvod znovuotevření je povinný');
    }

    $reqId = arrInt($req, 'id');
    $db->beginTransaction();
    try {
        $db->prepare(
            'UPDATE tel_requests
             SET status = \'reopened\', reopen_reason = ?, resolved_at = NULL,
                 assigned_to_id = NULL, assigned_at = NULL, updated_at = ?
             WHERE id = ?'
        )->execute([$reason, $now, $reqId]);
        logAudit($db, $reqId, $userId, 'reopened', 'status', 'resolved', 'reopened');
        logAudit($db, $reqId, $userId, 'field_edit', 'reopen_reason', null, $reason);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        appLog('reopen error: ' . $e->getMessage());
        jsonErr('Chyba při ukládání', 500);
    }

    jsonOk(['id' => $reqId]);
}

// ── edit_field ────────────────────────────────────────────────────────────────

/**
 * @param array<string, mixed> $req
 * @param array<string, mixed> $body
 */
function handleEditField(PDO $db, array $req, int $userId, string $now, array $body): never
{
    $field = arrStr($body, 'field');
    $value = trim(arrStr($body, 'value'));
    $isAdmin = isAdmin();

    // Povolená pole dle role a stavu
    $editableByUser  = ['spz', 'client_name', 'client_phone', 'client_email'];
    $editableAlways  = ['spz', 'client_name', 'client_phone', 'client_email', 'request_text', 'technician_note', 'pending_reason'];

    if (!in_array($field, $editableAlways, true)) {
        jsonErr('Pole nelze editovat');
    }

    if (!$isAdmin) {
        // User: jen před resolved
        if (in_array($req['status'], ['resolved', 'reopened'], true)) {
            jsonErr('Editace není po uzavření povolena');
        }
        // request_text jen před in_progress
        if ($field === 'request_text' && $req['status'] !== 'new') {
            jsonErr('Text požadavku lze editovat pouze před převzetím');
        }
        // technician_note a pending_reason jen přiřazený technik
        if (in_array($field, ['technician_note', 'pending_reason'], true)) {
            if (arrInt($req, 'assigned_to_id') !== $userId) {
                jsonErr('Toto pole může editovat pouze přiřazený technik');
            }
        }
        if (!in_array($field, $editableByUser, true) && !in_array($field, ['technician_note', 'pending_reason', 'request_text'], true)) {
            jsonErr('Nedostatečná oprávnění k editaci tohoto pole');
        }
    }

    // Validace délky
    if ($field === 'request_text' && mb_strlen($value) > 2000) {
        jsonErr('Text překračuje 2 000 znaků');
    }
    if ($field === 'client_email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
        jsonErr('Neplatný formát e-mailu');
    }

    $allowedColumns = ['spz', 'client_name', 'client_phone', 'client_email', 'request_text', 'technician_note', 'pending_reason'];
    if (!in_array($field, $allowedColumns, true)) {
        jsonErr('Nepovolené pole');
    }

    $storeValue = $field === 'spz' ? normalizeSpz($value) : $value;
    $oldValue   = arrStr($req, $field);

    $reqId = arrInt($req, 'id');
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE tel_requests SET `$field` = ?, updated_at = ? WHERE id = ?")
           ->execute([$storeValue ?: null, $now, $reqId]);
        logAudit($db, $reqId, $userId, 'field_edit', $field, $oldValue, $storeValue);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        appLog('edit_field error: ' . $e->getMessage());
        jsonErr('Chyba při ukládání', 500);
    }

    jsonOk(['id' => $reqId]);
}

// ── soft_delete ───────────────────────────────────────────────────────────────

/** @param array<string, mixed> $req */
function handleSoftDelete(PDO $db, array $req, int $userId, string $now): never
{
    if (!isAdmin()) {
        jsonErr('Nedostatečná oprávnění', 403);
    }

    $reqId = arrInt($req, 'id');
    $db->beginTransaction();
    try {
        $db->prepare(
            'UPDATE tel_requests SET deleted_at = ?, updated_at = ? WHERE id = ?'
        )->execute([$now, $now, $reqId]);
        logAudit($db, $reqId, $userId, 'soft_deleted');
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        appLog('soft_delete error: ' . $e->getMessage());
        jsonErr('Chyba při ukládání', 500);
    }

    jsonOk(['id' => $reqId]);
}
