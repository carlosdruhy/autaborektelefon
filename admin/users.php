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
<title>Uživatelé – Admin – <?= h(APP_NAME) ?></title>
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
        <a href="stats.php" class="btn btn-sm btn-outline-light">Statistiky</a>
        <a href="sms.php" class="btn btn-sm btn-outline-light">SMS</a>
        <a href="settings.php" class="btn btn-sm btn-outline-light">Nastavení</a>
        <a href="../logout.php" class="btn btn-sm btn-outline-light">Odhlásit</a>
    </div>
</nav>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">Správa uživatelů</h1>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#newUserModal">
            <i class="bi bi-person-plus"></i> Nový uživatel
        </button>
    </div>

    <div id="pageAlert" class="d-none"></div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0 admin-table">
                <thead>
                    <tr>
                        <th>Jméno</th>
                        <th>E-mail</th>
                        <th>Role</th>
                        <th>Stav</th>
                        <th>Znovuotevření</th>
                        <th>Poslední přihlášení</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="usersTable">
                    <tr><td colspan="6" class="text-center py-3">
                        <div class="spinner-border spinner-border-sm"></div>
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Nový uživatel -->
<div class="modal fade" id="newUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Nový uživatel</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="newUserAlert" class="d-none"></div>
                <form id="newUserForm" novalidate>
                    <div class="mb-3">
                        <label class="form-label">Jméno</label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">E-mail</label>
                        <input type="email" class="form-control" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <select class="form-select" name="role">
                            <option value="user">Uživatel</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="can_reopen"
                                   id="canReopenCheck" value="1" checked>
                            <label class="form-check-label" for="canReopenCheck">
                                Smí znovuotevřít uzavřené požadavky
                            </label>
                        </div>
                    </div>
                    <p class="text-muted small">Uživateli bude odeslán e-mail s odkazem pro nastavení hesla.</p>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zrušit</button>
                <button type="button" class="btn btn-primary" id="saveNewUserBtn">Vytvořit</button>
            </div>
        </div>
    </div>
</div>

<script>
const CSRF = '<?= h(arrStr($_SESSION, 'csrf_token')) ?>';
const API  = '<?= APP_URL ?>/admin/api/users.php';
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= assetUrl('assets/js/admin.js') ?>"></script>
<script>initUsersPage();</script>
</body>
</html>
