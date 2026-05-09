<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

startSecureSession();
requireAdmin();
checkSessionTimeout();
touchSession();

$from = arrStr($_GET, 'from', date('Y-m-01'));
$to   = arrStr($_GET, 'to', date('Y-m-d'));
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Statistiky – Admin – <?= h(APP_NAME) ?></title>
<link rel="icon" type="image/svg+xml" href="../favicon.svg">
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#3d3d3d">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Telefon">
<link rel="apple-touch-icon" href="/favicon.svg">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<nav class="navbar navbar-dark navbar-expand-sm app-navbar px-3">
    <a class="navbar-brand d-flex align-items-center gap-2 text-decoration-none" href="../dashboard.php">
        <span class="navbar-logo-wrap">
            <img src="../assets/img/logo.jpg" alt="Auta Borek a.s.">
        </span>
        <span class="navbar-app-title d-none d-md-block">Administrace</span>
    </a>
    <div class="ms-auto d-flex gap-2">
        <a href="../dashboard.php" class="btn btn-sm btn-outline-light">Přehled</a>
        <a href="stats.php" class="btn btn-sm btn-outline-light">Statistiky</a>
        <a href="sms.php" class="btn btn-sm btn-outline-light">SMS</a>
        <a href="settings.php" class="btn btn-sm btn-outline-light">Nastavení</a>
        <a href="../logout.php" class="btn btn-sm btn-outline-light">Odhlásit</a>
    </div>
</nav>

<div class="container py-4">
    <h1 class="h4 mb-3">Statistiky</h1>

    <!-- Filtr datumů -->
    <form method="get" class="d-flex gap-2 align-items-end mb-4">
        <div>
            <label class="form-label small mb-1">Od</label>
            <input type="date" class="form-control form-control-sm" name="from"
                   value="<?= h($from) ?>">
        </div>
        <div>
            <label class="form-label small mb-1">Do</label>
            <input type="date" class="form-control form-control-sm" name="to"
                   value="<?= h($to) ?>">
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Zobrazit</button>
    </form>

    <div class="row g-4">
        <!-- Podle techniků -->
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header fw-semibold">Podle techniků</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 admin-table">
                        <thead>
                            <tr>
                                <th>Technik</th>
                                <th class="text-end">Vyřízeno</th>
                                <th class="text-end">Prům. čas (min)</th>
                                <th class="text-end">Znovuotevřeno</th>
                            </tr>
                        </thead>
                        <tbody id="techTable">
                            <tr><td colspan="4" class="text-center py-2">
                                <div class="spinner-border spinner-border-sm"></div>
                            </td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Podle stáří -->
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header fw-semibold">Podle doby vyřízení</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 admin-table">
                        <thead>
                            <tr>
                                <th>Časové pásmo</th>
                                <th class="text-end">Počet</th>
                            </tr>
                        </thead>
                        <tbody id="ageTable">
                            <tr><td colspan="2" class="text-center py-2">
                                <div class="spinner-border spinner-border-sm"></div>
                            </td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const CSRF     = '<?= h(arrStr($_SESSION, 'csrf_token')) ?>';
const STATS_API = '<?= APP_URL ?>/api/stats.php';
const FROM_DATE = '<?= h($from) ?>';
const TO_DATE   = '<?= h($to) ?>';
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/admin.js"></script>
<script>initStatsPage();</script>
</body>
</html>
