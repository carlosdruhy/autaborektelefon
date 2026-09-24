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
    case 'create':
        if ($method !== 'POST') jsonErr('Metoda není povolena', 405);
        verifyCsrf();
        handleCreate();
    case 'toggle_active':
        if ($method !== 'POST') jsonErr('Metoda není povolena', 405);
        verifyCsrf();
        handleToggleActive();
    case 'toggle_reopen':
        if ($method !== 'POST') jsonErr('Metoda není povolena', 405);
        verifyCsrf();
        handleToggleReopen();
    case 'update':
        if ($method !== 'POST') jsonErr('Metoda není povolena', 405);
        verifyCsrf();
        handleUpdate();
    default:
        jsonErr('Neznámá akce', 400);
}

function handleList(): never
{
    $db   = getDB();
    $stmt = $db->query(
        'SELECT id, name, email, role, is_active, can_reopen, default_branch_id, created_at, last_login
         FROM tel_users
         ORDER BY name ASC'
    ) ?: throw new \RuntimeException('Query failed');
    $rows = $stmt->fetchAll();

    $ub = $db->query('SELECT user_id, branch_id FROM tel_user_branches ORDER BY branch_id')
        ?: throw new \RuntimeException('Query failed');
    $branchesByUser = [];
    foreach (pdoFetchAll($ub) as $link) {
        $branchesByUser[arrInt($link, 'user_id')][] = arrInt($link, 'branch_id');
    }

    foreach ($rows as &$r) {
        if ($r['created_at']) $r['created_at_local'] = toLocalTime($r['created_at']);
        if ($r['last_login'])  $r['last_login_local']  = toLocalTime($r['last_login']);
        $r['branch_ids'] = $branchesByUser[arrInt($r, 'id')] ?? [];
    }
    unset($r);
    jsonOk($rows);
}

/**
 * Uloží pobočky uživatele (M:N) a domovskou pobočku. Domovská musí být mezi přiřazenými;
 * když chybí, použije se první přiřazená. Bez poboček je domovská NULL.
 * @param array<string, mixed> $body
 */
function saveUserBranches(PDO $db, int $userId, array $body): void
{
    $raw = $body['branch_ids'] ?? [];
    $ids = [];
    if (is_array($raw)) {
        foreach ($raw as $v) {
            if (is_numeric($v) && (int) $v > 0) {
                $ids[] = (int) $v;
            }
        }
    }
    $ids = array_values(array_unique($ids));

    if ($ids !== []) {
        $in    = implode(',', array_fill(0, count($ids), '?'));
        $check = $db->prepare("SELECT COUNT(*) FROM tel_branches WHERE id IN ($in)");
        $check->execute($ids);
        if ((int) $check->fetchColumn() !== count($ids)) {
            jsonErr('Neplatná pobočka');
        }
    }

    $default = arrInt($body, 'default_branch_id');
    if (!in_array($default, $ids, true)) {
        $default = $ids[0] ?? 0;
    }

    $db->prepare('DELETE FROM tel_user_branches WHERE user_id = ?')->execute([$userId]);
    $ins = $db->prepare('INSERT INTO tel_user_branches (user_id, branch_id) VALUES (?, ?)');
    foreach ($ids as $branchId) {
        $ins->execute([$userId, $branchId]);
    }
    $db->prepare('UPDATE tel_users SET default_branch_id = ? WHERE id = ?')
       ->execute([$default > 0 ? $default : null, $userId]);
}

function handleCreate(): never
{
    $body  = getPostedJson();
    $name  = trim(arrStr($body, 'name'));
    $email = trim(arrStr($body, 'email'));
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
    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            'INSERT INTO tel_users (name, email, role, is_active, can_reopen, created_at)
             VALUES (?, ?, ?, 1, ?, ?)'
        );
        $stmt->execute([$name, $email, $role, $canReopen, $now]);
        $userId = (int)$db->lastInsertId();
        saveUserBranches($db, $userId, $body);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        appLog('user create error: ' . $e->getMessage());
        jsonErr('Chyba při ukládání', 500);
    }

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
    $id   = arrInt($body, 'id');
    if ($id <= 0) jsonErr('Chybí ID');

    $db = getDB();
    $stmt = $db->prepare('SELECT can_reopen FROM tel_users WHERE id = ?');
    $stmt->execute([$id]);
    $user = pdoFetch($stmt);
    if (!$user) jsonErr('Uživatel nenalezen', 404);

    $newState = arrInt($user, 'can_reopen') ? 0 : 1;
    $db->prepare('UPDATE tel_users SET can_reopen = ? WHERE id = ?')
       ->execute([$newState, $id]);

    jsonOk(['id' => $id, 'can_reopen' => $newState]);
}

function handleUpdate(): never
{
    $body  = getPostedJson();
    $id    = arrInt($body, 'id');
    $name  = trim(arrStr($body, 'name'));
    $email = trim(arrStr($body, 'email'));

    if ($id <= 0) jsonErr('Chybí ID');
    if ($name === '') jsonErr('Jméno je povinné');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonErr('Neplatný e-mail');

    $db = getDB();

    $stmt = $db->prepare('SELECT id FROM tel_users WHERE id = ?');
    $stmt->execute([$id]);
    if (!pdoFetch($stmt)) jsonErr('Uživatel nenalezen', 404);

    $check = $db->prepare('SELECT id FROM tel_users WHERE email = ? AND id != ?');
    $check->execute([$email, $id]);
    if (pdoFetch($check)) jsonErr('Uživatel s tímto e-mailem již existuje');

    $db->beginTransaction();
    try {
        $db->prepare('UPDATE tel_users SET name = ?, email = ? WHERE id = ?')
           ->execute([$name, $email, $id]);
        if (array_key_exists('branch_ids', $body)) {
            saveUserBranches($db, $id, $body);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        appLog('user update error: ' . $e->getMessage());
        jsonErr('Chyba při ukládání', 500);
    }

    jsonOk(['id' => $id]);
}

function handleToggleActive(): never
{
    $body = getPostedJson();
    $id   = arrInt($body, 'id');

    if ($id <= 0) jsonErr('Chybí ID');
    if ($id === currentUserId()) jsonErr('Nelze zablokovat sebe sama');

    $db = getDB();
    $stmt = $db->prepare('SELECT is_active FROM tel_users WHERE id = ?');
    $stmt->execute([$id]);
    $user = pdoFetch($stmt);
    if (!$user) jsonErr('Uživatel nenalezen', 404);

    $newState = arrInt($user, 'is_active') ? 0 : 1;
    $db->prepare('UPDATE tel_users SET is_active = ? WHERE id = ?')
       ->execute([$newState, $id]);

    jsonOk(['id' => $id, 'is_active' => $newState]);
}
