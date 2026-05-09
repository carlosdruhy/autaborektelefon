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

$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email     = trim(arrStr($_POST, 'email'));
    $ip        = arrStr($_SERVER, 'REMOTE_ADDR', '0.0.0.0');
    $formToken = arrStr($_POST, 'csrf_token');

    // Vždy zobrazit stejnou zprávu (prevence user enumeration)
    $sent = true;

    if (!hash_equals(arrStr($_SESSION, 'csrf_login'), $formToken)) {
        // Tiché odmítnutí — neunikají informace
    } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) && checkRateLimit('reset', $ip, $email)) {
        $stmt = getDB()->prepare('SELECT id, name, email FROM tel_users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = pdoFetch($stmt);

        if ($user) {
            // Invaliduj staré tokeny
            $db = getDB();
            $db->prepare(
                'UPDATE tel_password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL'
            )->execute([nowUtc(), arrInt($user, 'id')]);

            // Nový token
            $token     = generateToken(32);
            $now       = nowUtc();
            $expiresAt = gmdate('Y-m-d H:i:s', time() + 86400);

            $db->prepare(
                'INSERT INTO tel_password_resets (user_id, token, created_at, expires_at)
                 VALUES (?, ?, ?, ?)'
            )->execute([arrInt($user, 'id'), $token, $now, $expiresAt]);

            sendPasswordResetEmail($user, $token);
        }
        // recordRateFail se nevolá pro neexistující e-mail
    }
}

if (empty($_SESSION['csrf_login'])) {
    $_SESSION['csrf_login'] = generateToken();
}
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Zapomenuté heslo – <?= h(APP_NAME) ?></title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#3d3d3d">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Telefon">
<link rel="apple-touch-icon" href="/favicon.svg">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="<?= assetUrl('assets/css/style.css') ?>">
</head>
<body class="bg-light d-flex align-items-center min-vh-100">
<div class="container">
    <div class="login-card mx-auto">
        <div class="text-center mb-4">
            <img src="assets/img/logo.jpg" alt="Auta Borek a.s." class="login-logo mb-2">
        </div>

        <?php if ($sent): ?>
            <div class="alert alert-info">
                Pokud e-mail existuje, byl odeslán odkaz pro nastavení hesla. Zkontrolujte svou schránku (platnost 24 hodin).
            </div>
            <div class="text-center mt-3">
                <a href="login.php">Zpět na přihlášení</a>
            </div>
        <?php else: ?>
            <h2 class="h5 mb-3">Zapomenuté heslo</h2>
            <form method="post" novalidate>
                <input type="hidden" name="csrf_token" value="<?= h(arrStr($_SESSION, 'csrf_login')) ?>">
                <div class="mb-3">
                    <label for="email" class="form-label">E-mail</label>
                    <input type="email" class="form-control" id="email" name="email"
                           autocomplete="email" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary w-100 mb-3">Odeslat odkaz</button>
                <div class="text-center">
                    <a href="login.php" class="text-muted small">Zpět na přihlášení</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
