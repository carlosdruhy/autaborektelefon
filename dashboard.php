<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

startSecureSession();
requireLogin();
checkSessionTimeout();
touchSession();
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(APP_NAME) ?></title>
<meta name="csrf-token" content="<?= h(arrStr($_SESSION, 'csrf_token')) ?>">
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#3d3d3d">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Telefon">
<link rel="apple-touch-icon" href="/favicon.svg">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-dark navbar-expand-sm app-navbar px-3">
    <a class="navbar-brand d-flex align-items-center gap-2 text-decoration-none" href="dashboard.php">
        <span class="navbar-logo-wrap">
            <img src="assets/img/logo.jpg" alt="Auta Borek a.s.">
        </span>
        <span class="navbar-app-title d-none d-md-block">Evidence telefonických<br>požadavků</span>
    </a>
    <div class="ms-auto d-flex align-items-center gap-2">
        <span class="text-white-50 small d-none d-sm-inline">
            <?= h(currentUserName()) ?><span id="sessionCountdown" class="ms-1 opacity-75"></span>
        </span>
        <?php if (isAdmin()): ?>
            <a href="admin/" class="btn btn-sm btn-outline-light">
                <i class="bi bi-gear-fill"></i> Admin
            </a>
        <?php endif; ?>
        <a href="logout.php" class="btn btn-sm btn-outline-light">
            <i class="bi bi-box-arrow-right"></i> Odhlásit
        </a>
    </div>
</nav>

<!-- Session warning banner -->
<div id="sessionWarning" class="alert alert-warning alert-dismissible m-0 d-none rounded-0 text-center" role="alert">
    <strong>Vaše session vyprší za 5 minut.</strong>
    <button type="button" class="btn btn-sm btn-warning ms-3" onclick="extendSession()">Prodloužit</button>
</div>

<div class="container-fluid py-3">

    <!-- Toolbar -->
    <div class="req-toolbar mb-3 d-flex flex-wrap align-items-center gap-2">

        <!-- Nový požadavek -->
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#newRequestModal">
            <i class="bi bi-plus-lg"></i> Nový požadavek
        </button>

        <div class="vr mx-1"></div>

        <!-- Interval -->
        <div class="d-flex align-items-center gap-1">
            <label class="form-label mb-0 small text-muted">Interval:</label>
            <select id="refreshSelect" class="form-select form-select-sm" style="width:auto">
                <option value="15">15 s</option>
                <option value="30">30 s</option>
                <option value="60">60 s</option>
                <option value="120">2 min</option>
                <option value="300">5 min</option>
            </select>
        </div>

        <!-- Řazení -->
        <button id="sortBtn" class="btn btn-sm btn-outline-secondary" title="Přepnout řazení">
            <i class="bi bi-sort-up" id="sortIcon"></i>
        </button>

        <!-- Filtry -->
        <div class="btn-group btn-group-sm" role="group">
            <button class="btn btn-outline-secondary filter-btn active" data-filter="all">Vše</button>
            <button class="btn btn-outline-secondary filter-btn" data-filter="new">Nové</button>
            <button class="btn btn-outline-secondary filter-btn" data-filter="in_progress">Převzaté</button>
            <button class="btn btn-outline-secondary filter-btn" data-filter="pending">Čekající</button>
            <button class="btn btn-outline-secondary filter-btn" data-filter="reopened">Znovuotevřené</button>
            <button class="btn btn-outline-secondary filter-btn" data-filter="resolved">Vyřízené</button>
            <button class="btn btn-outline-secondary filter-btn" data-filter="mine">Jen moje</button>
        </div>

        <!-- Vyhledávání -->
        <div class="flex-grow-1" style="min-width:180px;max-width:300px">
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="search" id="searchInput" class="form-control"
                       placeholder="SPZ, jméno, telefon…">
            </div>
        </div>

        <!-- Obnovit + odpočet -->
        <button id="refreshNowBtn" class="btn btn-sm btn-outline-secondary ms-auto">
            <i class="bi bi-arrow-clockwise"></i> Obnovit
        </button>
        <div class="d-flex align-items-center gap-1">
            <span id="countdownLabel" class="text-muted small">–</span>
            <div class="progress" style="width:60px;height:4px">
                <div id="countdownBar" class="progress-bar" style="width:100%;background:var(--color-accent)"></div>
            </div>
        </div>

        <!-- Zvuk -->
        <button id="soundBtn" class="btn btn-sm btn-outline-secondary" title="Zvukové upozornění">
            <i class="bi bi-volume-mute-fill" id="soundIcon"></i>
        </button>

        <!-- Notifikace -->
        <button id="notifBtn" class="btn btn-sm btn-outline-secondary" title="Klikněte pro zapnutí upozornění na nové požadavky">
            <i class="bi bi-bell" id="notifIcon"></i>
        </button>
    </div>

    <!-- Počet výsledků -->
    <div class="mb-2">
        <small class="text-muted" id="resultCount"></small>
    </div>

    <!-- Kontejner karet -->
    <div id="requestList">
        <div class="text-center text-muted py-5">
            <div class="spinner-border spinner-border-sm"></div> Načítám…
        </div>
    </div>
</div>

<!-- Modální okno: Nový požadavek -->
<div class="modal fade" id="newRequestModal" tabindex="-1" aria-labelledby="newRequestModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="newRequestModalLabel">
                    <i class="bi bi-telephone-plus"></i> Nový telefonický požadavek
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zavřít"></button>
            </div>
            <div class="modal-body">
                <div id="newRequestAlert" class="d-none mb-3"></div>
                <form id="newRequestForm" novalidate>
                    <div class="row g-2 mb-2">
                        <div class="col-sm-4">
                            <label class="form-label">SPZ <span class="text-danger">*</span></label>
                            <input type="text" class="form-control text-uppercase" name="spz"
                                   maxlength="20" required autocomplete="off" placeholder="1AB1234">
                        </div>
                        <div class="col-sm-8">
                            <label class="form-label">Jméno klienta <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="client_name"
                                   maxlength="100" required autocomplete="off">
                        </div>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-sm-6">
                            <label class="form-label">Telefon</label>
                            <input type="tel" class="form-control" name="client_phone"
                                   maxlength="30" autocomplete="off">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">E-mail</label>
                            <input type="email" class="form-control" name="client_email"
                                   maxlength="255" autocomplete="off">
                        </div>
                    </div>
                    <div class="mb-1">
                        <label class="form-label">Požadavek <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="request_text" rows="4"
                                  maxlength="2000" required></textarea>
                        <div class="form-text text-end"><span id="charCount">0</span> / 2000</div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg"></i> Zrušit
                </button>
                <button type="submit" form="newRequestForm" class="btn btn-primary" id="submitNewRequest">
                    <i class="bi bi-send"></i> Odeslat požadavek
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modální okno -->
<div class="modal fade" id="requestModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Detail požadavku</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalBody">
                <div class="text-center py-4"><div class="spinner-border"></div></div>
            </div>
            <div class="modal-footer" id="modalFooter"></div>
        </div>
    </div>
</div>

<script>
const APP = {
    apiBase:         '<?= APP_URL ?>/api',
    appName:         '<?= h(APP_NAME) ?>',
    csrfToken:       '<?= h(arrStr($_SESSION, 'csrf_token')) ?>',
    refreshInterval: <?= getSettingInt('refresh_interval', 30) ?>,
    sessionTimeout:  <?= getSettingInt('session_timeout', 500) ?>,
    colorThresholds: <?= json_encode([
        getSettingInt('color_level_1', 15),
        getSettingInt('color_level_2', 30),
        getSettingInt('color_level_3', 60),
        getSettingInt('color_level_4', 120),
    ]) ?>,
    currentUser: {
        id:        <?= currentUserId() ?>,
        name:      '<?= h(currentUserName()) ?>',
        role:      '<?= h(currentUserRole()) ?>',
        isAdmin:   <?= isAdmin() ? 'true' : 'false' ?>,
        canReopen: <?= (isAdmin() || currentUserCanReopen()) ? 'true' : 'false' ?>
    },
    smsEnabled: <?= getSetting('sms_enabled', '0') ? 'true' : 'false' ?>
};
</script>
<!-- Modální okno: Historie SMS -->
<div class="modal fade" id="smsHistoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="smsHistoryTitle"><i class="bi bi-chat-dots"></i> Odeslané SMS</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" id="smsHistoryBody">
                <div class="text-center py-4"><div class="spinner-border spinner-border-sm"></div></div>
            </div>
        </div>
    </div>
</div>

<!-- Modální okno: SMS -->
<div class="modal fade" id="smsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-chat-dots"></i> Odeslat SMS klientovi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="smsAlert" class="d-none mb-3"></div>
                <div class="mb-3">
                    <label class="form-label">Příjemce</label>
                    <input type="text" class="form-control" id="smsPhone" readonly>
                </div>
                <div class="mb-2">
                    <label class="form-label">Text SMS <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="smsText" rows="4" maxlength="400"
                              placeholder="Text zprávy…"></textarea>
                    <div class="form-text d-flex justify-content-between">
                        <span id="smsSegmentInfo" class="text-muted">1 SMS</span>
                        <span><span id="smsCharCount">0</span> znaků</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" id="smsSendBtn">
                    <i class="bi bi-send"></i> Odeslat SMS
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
