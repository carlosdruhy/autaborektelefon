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
        break;
    case 'create':
        if ($method !== 'POST') jsonErr('Metoda není povolena', 405);
        verifyCsrf();
        handleCreate();
        break;
    case 'toggle_active':
        if ($method !== 'POST') jsonErr('Metoda není povolena', 405);
        verifyCsrf();
        handleToggleActive();
        break;
    case 'toggle_reopen':
        if ($method !== 'POST') jsonErr('Metoda není povolena', 405);
        verifyCsrf();
        handleToggleReopen();
        break;
    default:
        jsonErr('Neznámá akce', 400);
}

function handleList(): never
{
    $stmt = getDB()->query(
        'SELECT id, name, email, role, is_active, can_reopen, created_at, last_login
         FROM tel_users
         ORDER BY name ASC'
    );
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        if ($r['created_at']) $r['created_at_local'] = toLocalTime($r['created_at']);
        if ($r['last_login'])  $r['last_login_local']  = toLocalTime($r['last_login']);
    }
    unset($r);
    jsonOk($rows);
}

function handleCreate(): never
{
    $body  = getPostedJson();
    $name  = trim($body['name']  ?? '');
    $email = trim($body['email'] ?? '');
    $role  = $body['role'] ?? 'user';

    if ($name === '') jsonErr('Jméno je povinné');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonErr('Neplatný e-mail');
    if (!in_array($role, ['admin', 'user'], true)) jsonErr('Neplatná role');

    $db = getDB();

    // Kontrola duplicity
    $check = $db->prepare('SELECT id FROM tel_users WHERE email = ?');
    $check->execute([$email]);
    if ($check->fetch()) {
        jsonErr('Uživatel s tímto e-mailem již existuje');
    }

    $canReopen = isset($body['can_reopen']) ? (int)(bool)$body['can_reopen'] : 1;
    $now = nowUtc();
    $stmt = $db->prepare(
        'INSERT INTO tel_users (name, email, role, is_active, can_reopen, created_at)
         VALUES (?, ?, ?, 1, ?, ?)'
    );
    $stmt->execute([$name, $email, $role, $canReopen, $now]);
    $userId = (int)$db->lastInsertId();

    // Vygeneruj reset token a odešli e-mail
    $token     = generateToken(32);
    $expiresAt = gmdate('Y-m-d H:i:s', time() + 86400);
    $db->prepare(
        'INSERT INTO tel_password_resets (user_id, token, created_at, expires_at)
         VALUES (?, ?, ?, ?)'
    )->execute([$userId, $token, $now, $expiresAt]);

    $user = ['id' => $userId, 'name' => $name, 'email' => $email];
    sendPasswordResetEmail($user, $token);

    jsonOk(['id' => $userId, 'message' => 'Uživatel vytvořen, e-mail odeslán.']);
}

function handleToggleReopen(): never
{
    $body = getPostedJson();
    $id   = (int)($body['id'] ?? 0);
    if ($id <= 0) jsonErr('Chybí ID');

    $db = getDB();
    $stmt = $db->prepare('SELECT can_reopen FROM tel_users WHERE id = ?');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) jsonErr('Uživatel nenalezen', 404);

    $newState = $user['can_reopen'] ? 0 : 1;
    $db->prepare('UPDATE tel_users SET can_reopen = ? WHERE id = ?')
       ->execute([$newState, $id]);

    jsonOk(['id' => $id, 'can_reopen' => $newState]);
}

function handleToggleActive(): never
{
    $body = getPostedJson();
    $id   = (int)($body['id'] ?? 0);

    if ($id <= 0) jsonErr('Chybí ID');
    if ($id === currentUserId()) jsonErr('Nelze zablokovat sebe sama');

    $db = getDB();
    $stmt = $db->prepare('SELECT is_active FROM tel_users WHERE id = ?');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) jsonErr('Uživatel nenalezen', 404);

    $newState = $user['is_active'] ? 0 : 1;
    $db->prepare('UPDATE tel_users SET is_active = ? WHERE id = ?')
       ->execute([$newState, $id]);

    jsonOk(['id' => $id, 'is_active' => $newState]);
}
