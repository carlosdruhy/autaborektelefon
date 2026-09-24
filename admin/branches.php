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
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pobočky – Admin – <?= h(APP_NAME) ?></title>
<link rel="icon" type="image/svg+xml" href="../favicon.svg">
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#3d3d3d">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Telefon">
<link rel="apple-touch-icon" href="/favicon.svg">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= assetUrl('assets/css/style.css') ?>">
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
        <a href="branches.php" class="btn btn-sm btn-outline-light">Pobočky</a>
        <a href="stats.php" class="btn btn-sm btn-outline-light">Statistiky</a>
        <a href="sms.php" class="btn btn-sm btn-outline-light">SMS</a>
        <a href="settings.php" class="btn btn-sm btn-outline-light">Nastavení</a>
        <a href="import-vehicles.php" class="btn btn-sm btn-outline-light">Vozidla</a>
        <a href="orders.php" class="btn btn-sm btn-outline-light">Objednávky</a>
        <a href="../logout.php" class="btn btn-sm btn-outline-light">Odhlásit</a>
    </div>
</nav>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">Pobočky</h1>
        <button class="btn btn-primary btn-sm" onclick="openBranchModal(null)">
            <i class="bi bi-plus-lg"></i> Nová pobočka
        </button>
    </div>

    <div id="pageAlert" class="d-none"></div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0 admin-table">
                <thead>
                    <tr>
                        <th>Kód</th>
                        <th>Název</th>
                        <th>Středisko DMS</th>
                        <th class="text-end">Pořadí</th>
                        <th class="text-end">Uživatelé</th>
                        <th class="text-end">Nevyřízené</th>
                        <th>Stav</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="branchesTable">
                    <tr><td colspan="8" class="text-center py-3">
                        <div class="spinner-border spinner-border-sm"></div>
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-muted small mt-3 mb-0">
        Pobočky se nemažou, jen deaktivují — historické požadavky dál ukazují, kam patřily.
        Víc poboček může sdílet jedno středisko DMS (např. servis a lakovna Borek = středisko 3);
        podle střediska se u termínu z plánovače ukazuje, kde je vůz objednaný.
        Uživatele k pobočkám přiřadíte na stránce <a href="users.php">Uživatelé</a>.
    </p>
</div>

<!-- Modal: Pobočka -->
<div class="modal fade" id="branchModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="branchModalTitle">Pobočka</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="branchAlert" class="d-none"></div>
                <form id="branchForm" novalidate>
                    <input type="hidden" name="id" value="0">
                    <div class="row g-2 mb-3">
                        <div class="col-4">
                            <label class="form-label">Kód</label>
                            <input type="text" class="form-control text-uppercase" name="code" maxlength="20"
                                   required placeholder="BOR">
                        </div>
                        <div class="col-8">
                            <label class="form-label">Název</label>
                            <input type="text" class="form-control" name="name" maxlength="100"
                                   required placeholder="Borek – servis">
                        </div>
                    </div>
                    <div class="row g-2 mb-1">
                        <div class="col-6">
                            <label class="form-label">Středisko DMS</label>
                            <input type="text" class="form-control" name="dms_center_code" maxlength="20" placeholder="3">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Pořadí</label>
                            <input type="number" class="form-control" name="sort_order" value="0" step="10">
                        </div>
                    </div>
                    <div class="form-text">Kód se zobrazuje na kartě požadavku; stačí 2–4 znaky.</div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zrušit</button>
                <button type="button" class="btn btn-primary" id="saveBranchBtn">Uložit</button>
            </div>
        </div>
    </div>
</div>

<script>
const CSRF = '<?= h(arrStr($_SESSION, 'csrf_token')) ?>';
const BRANCHES_API = '<?= APP_URL ?>/admin/api/branches.php';
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= assetUrl('assets/js/admin.js') ?>"></script>
<script>initBranchesPage();</script>
</body>
</html>
