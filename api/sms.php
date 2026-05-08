<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

// ─── Bridge endpointy (autentizace API klíčem, bez session) ──────────────────

if ($action === 'pending' || $action === 'confirm') {
    $bridgeKey = getSetting('sms_bridge_key', '');
    $reqKey    = $_GET['key'] ?? '';
    if ($bridgeKey === '' || !hash_equals($bridgeKey, $reqKey)) {
        jsonErr('Unauthorized', 401);
    }
    if ($action === 'pending') {
        handlePending();
    }
    handleConfirm();
}

// ─── Uživatelské endpointy (session) ─────────────────────────────────────────

startSecureSession();
requireLogin();
checkSessionTimeout();
touchSession();

if ($action === 'list') {
    handleSmsList();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = getPostedJson();
    verifyCsrf($body['csrf'] ?? '');
    handleEnqueue($body);
}

jsonErr('Bad request');

// ─── Handlery ────────────────────────────────────────────────────────────────

function handleSmsList(): never
{
    $requestId = isset($_GET['request_id']) ? (int) $_GET['request_id'] : null;

    if ($requestId !== null) {
        $stmt = getDB()->prepare(
            'SELECT q.id, q.phone, q.message, q.status, q.created_at, q.sent_at, q.error_msg,
                    u.name AS sent_by_name
             FROM tel_sms_queue q
             JOIN tel_users u ON q.sent_by = u.id
             WHERE q.request_id = ?
             ORDER BY q.id DESC'
        );
        $stmt->execute([$requestId]);
    } elseif (isAdmin()) {
        $stmt = getDB()->prepare(
            'SELECT q.id, q.phone, q.message, q.status, q.created_at, q.sent_at, q.error_msg,
                    u.name AS sent_by_name,
                    r.spz, r.client_name, q.request_id
             FROM tel_sms_queue q
             JOIN tel_users u ON q.sent_by = u.id
             LEFT JOIN tel_requests r ON q.request_id = r.id
             ORDER BY q.id DESC
             LIMIT 300'
        );
        $stmt->execute([]);
    } else {
        jsonErr('Přístup odepřen', 403);
    }

    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['created_at_local'] = toLocalTime($row['created_at']);
        $row['sent_at_local']    = $row['sent_at'] ? toLocalTime($row['sent_at']) : null;
    }
    unset($row);
    jsonOk($rows);
}

function handlePending(): never
{
    $stmt = getDB()->prepare(
        'SELECT id, phone, message FROM tel_sms_queue
         WHERE status = ? ORDER BY id ASC LIMIT 50'
    );
    $stmt->execute(['pending']);
    jsonOk($stmt->fetchAll());
}

function handleConfirm(): never
{
    $results = getPostedJson()['results'] ?? [];
    $db      = getDB();
    $update  = $db->prepare(
        'UPDATE tel_sms_queue
         SET status = ?, sent_at = ?, error_msg = ?
         WHERE id = ?'
    );
    foreach ($results as $r) {
        $id      = (int) ($r['id'] ?? 0);
        $success = (bool) ($r['success'] ?? false);
        $errMsg  = $success ? null : substr((string) ($r['error'] ?? 'Neznámá chyba'), 0, 255);
        $update->execute([
            $success ? 'sent' : 'failed',
            $success ? nowUtc() : null,
            $errMsg,
            $id,
        ]);
    }
    jsonOk(['updated' => count($results)]);
}

function handleEnqueue(array $body): never
{
    if (!getSetting('sms_enabled', '0')) {
        jsonErr('SMS není povoleno', 503);
    }
    $requestId = isset($body['request_id']) ? (int) $body['request_id'] : null;
    $phone     = trim($body['phone']   ?? '');
    $message   = trim($body['message'] ?? '');

    if ($phone === '' || $message === '') {
        jsonErr('Telefon a text zprávy jsou povinné');
    }
    if (mb_strlen($message) > 400) {
        jsonErr('Zpráva může mít maximálně 400 znaků');
    }

    $db = getDB();
    $db->prepare(
        'INSERT INTO tel_sms_queue (request_id, sent_by, phone, message, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$requestId, currentUserId(), $phone, $message, 'pending', nowUtc()]);

    jsonOk(['queued' => true]);
}
