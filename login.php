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

$error   = '';
$timeout = isset($_GET['timeout']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim(arrStr($_POST, 'email'));
    $pass  = arrStr($_POST, 'password');
    $ip    = arrStr($_SERVER, 'REMOTE_ADDR', '0.0.0.0');

    // CSRF pro HTML formulář — token z hidden pole
    $formToken    = arrStr($_POST, 'csrf_token');
    $sessionToken = arrStr($_SESSION, 'csrf_login');
    if (!$sessionToken || !hash_equals($sessionToken, $formToken)) {
        $error = 'Neplatný požadavek. Zkuste znovu.';
    } elseif (!checkRateLimit('login', $ip, $email)) {
        $error = 'Příliš mnoho neúspěšných pokusů. Zkuste to za chvíli.';
    } else {
        $stmt = getDB()->prepare(
            'SELECT id, name, email, password_hash, role, is_active, can_reopen
             FROM tel_users WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = pdoFetch($stmt);

        if (!$user || !(bool)$user['is_active']) {
            recordRateFail('login', $ip, $email);
            $error = 'Nesprávný e-mail nebo heslo.';
        } elseif ($user['password_hash'] === null) {
            $error = 'Účet zatím nemá heslo. Použijte <a href="forgot-password.php">Zapomenuté heslo</a>.';
        } elseif (!password_verify($pass, arrStr($user, 'password_hash'))) {
            recordRateFail('login', $ip, $email);
            $error = 'Nesprávný e-mail nebo heslo.';
        } else {
            loginUser($user);
            header('Location: ' . APP_URL . '/dashboard.php');
            exit;
        }
    }
}

// Vygenerování CSRF tokenu pro login formulář
if (empty($_SESSION['csrf_login'])) {
    $_SESSION['csrf_login'] = generateToken();
}
$csrfLogin = arrStr($_SESSION, 'csrf_login');
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Přihlášení – <?= h(APP_NAME) ?></title>
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
            <img src="assets/img/logo.jpg" alt="Auta Borek a.s." class="login-logo mb-3">
            <p class="text-muted mb-0" style="font-size:.85rem">Evidence telefonických požadavků</p>
        </div>

        <?php if ($timeout): ?>
            <div class="alert alert-warning">Session vypršela. Přihlaste se znovu.</div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= $error ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['reset']) && $_GET['reset'] === 'done'): ?>
            <div class="alert alert-success">Heslo bylo nastaveno. Přihlaste se.</div>
        <?php endif; ?>

        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= h($csrfLogin) ?>">
            <div class="mb-3">
                <label for="email" class="form-label">E-mail</label>
                <input type="email" class="form-control" id="email" name="email"
                       value="<?= h(arrStr($_POST, 'email')) ?>"
                       autocomplete="email" required autofocus>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Heslo</label>
                <input type="password" class="form-control" id="password" name="password"
                       autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn btn-primary w-100 mb-3">Přihlásit se</button>
            <div class="text-center">
                <a href="forgot-password.php" class="text-muted small">Zapomenuté heslo</a>
            </div>
        </form>
    </div>
</div>
<script>if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js').catch(() => {});</script>
</body>
</html>
