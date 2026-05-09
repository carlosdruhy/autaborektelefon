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
<title>SMS – Admin – <?= h(APP_NAME) ?></title>
<link rel="icon" type="image/svg+xml" href="../favicon.svg">
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#3d3d3d">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
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
    <h1 class="h4 mb-3">Přehled odeslaných SMS</h1>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 admin-table">
                <thead>
                    <tr>
                        <th>Požadavek</th>
                        <th>Klient</th>
                        <th>Telefon</th>
                        <th>Zpráva</th>
                        <th>Stav</th>
                        <th>Odesílatel</th>
                        <th>Vytvořeno</th>
                        <th>Odesláno</th>
                    </tr>
                </thead>
                <tbody id="smsTable">
                    <tr><td colspan="8" class="text-center py-3">
                        <div class="spinner-border spinner-border-sm"></div>
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
const CSRF    = '<?= h(arrStr($_SESSION, 'csrf_token')) ?>';
const SMS_API = '<?= APP_URL ?>/api/sms.php';
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(async function () {
    const tbody = document.getElementById('smsTable');
    try {
        const res = await fetch(SMS_API + '?action=list', { credentials: 'same-origin' });
        const json = await res.json();

        if (!json.success || !json.data.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-3">Žádné SMS.</td></tr>';
            return;
        }

        const badge = s => {
            if (s === 'sent')   return '<span class="badge bg-success">Odesláno</span>';
            if (s === 'failed') return '<span class="badge bg-danger">Chyba</span>';
            return '<span class="badge bg-secondary">Čeká</span>';
        };
        const esc = s => s == null ? '' : String(s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

        tbody.innerHTML = json.data.map(r => `<tr>
            <td>${r.request_id ? `<a href="../dashboard.php">#${r.request_id} ${esc(r.spz)}</a>` : '—'}</td>
            <td>${esc(r.client_name)}</td>
            <td>${esc(r.phone)}</td>
            <td class="text-truncate" style="max-width:220px" title="${esc(r.message)}">${esc(r.message)}</td>
            <td>${badge(r.status)}${r.error_msg ? `<br><small class="text-danger">${esc(r.error_msg)}</small>` : ''}</td>
            <td>${esc(r.sent_by_name)}</td>
            <td class="text-nowrap">${esc(r.created_at_local)}</td>
            <td class="text-nowrap">${r.sent_at_local || '—'}</td>
        </tr>`).join('');
    } catch (e) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger py-3">Chyba načítání.</td></tr>';
    }
})();
</script>
</body>
</html>
