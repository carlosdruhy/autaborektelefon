<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

startSecureSession();

if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/dashboard.php');
    exit;
}

$token   = trim($_GET['token'] ?? '');
$error   = '';
$invalid = false;

function loadValidToken(string $token): array|false
{
    if ($token === '') {
        return false;
    }
    $stmt = getDB()->prepare(
        'SELECT pr.id, pr.user_id, pr.expires_at, u.name, u.email
         FROM tel_password_resets pr
         JOIN tel_users u ON pr.user_id = u.id
         WHERE pr.token = ? AND pr.used_at IS NULL AND pr.expires_at > ?
         LIMIT 1'
    );
    $stmt->execute([$token, nowUtc()]);
    return $stmt->fetch() ?: false;
}

$tokenRow = loadValidToken($token);
if (!$tokenRow) {
    $invalid = true;
}

if (!$invalid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPass  = $_POST['password']  ?? '';
    $newPass2 = $_POST['password2'] ?? '';
    $postToken = $_POST['token']    ?? '';

    // Znovu ověř token (replay protection)
    $tokenRow = loadValidToken($postToken);
    if (!$tokenRow) {
        $invalid = true;
    } elseif (strlen($newPass) < 8) {
        $error = 'Heslo musí mít alespoň 8 znaků.';
    } elseif ($newPass !== $newPass2) {
        $error = 'Hesla se neshodují.';
    } else {
        $db   = getDB();
        $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);

        $db->prepare('UPDATE tel_users SET password_hash = ? WHERE id = ?')
           ->execute([$hash, $tokenRow['user_id']]);

        $db->prepare('UPDATE tel_password_resets SET used_at = ? WHERE id = ?')
           ->execute([nowUtc(), $tokenRow['id']]);

        header('Location: ' . APP_URL . '/login.php?reset=done');
        exit;
    }
}
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Nastavení hesla – <?= h(APP_NAME) ?></title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#3d3d3d">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Telefon">
<link rel="apple-touch-icon" href="/favicon.svg">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-light d-flex align-items-center min-vh-100">
<div class="container">
    <div class="login-card mx-auto">
        <div class="text-center mb-4">
            <img src="assets/img/logo.jpg" alt="Auta Borek a.s." class="login-logo mb-2">
        </div>

        <?php if ($invalid): ?>
            <div class="alert alert-danger">
                Odkaz je neplatný nebo vypršel.
            </div>
            <div class="text-center">
                <a href="forgot-password.php">Požádat o nový odkaz</a>
            </div>
        <?php else: ?>
            <h2 class="h5 mb-1">Nastavení hesla</h2>
            <p class="text-muted mb-3">Pro účet <strong><?= h($tokenRow['email']) ?></strong></p>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post" novalidate>
                <input type="hidden" name="token" value="<?= h($token) ?>">
                <div class="mb-3">
                    <label for="password" class="form-label">Nové heslo (min. 8 znaků)</label>
                    <input type="password" class="form-control" id="password" name="password"
                           autocomplete="new-password" required autofocus>
                </div>
                <div class="mb-3">
                    <label for="password2" class="form-label">Heslo znovu</label>
                    <input type="password" class="form-control" id="password2" name="password2"
                           autocomplete="new-password" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Nastavit heslo</button>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
