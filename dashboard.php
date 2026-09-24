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

// Pobočky (PRD 3.14): všechny aktivní pro výběr v novém požadavku, vlastní pro filtr
$db             = getDB();
$activeBranches = getBranches($db, true);
$myBranchIds    = userBranchIds($db, currentUserId());
$activeIds      = array_column($activeBranches, 'id');
$filterBranches = isAdmin()
    ? $activeBranches
    : array_values(array_filter($activeBranches, static fn (array $b): bool => in_array($b['id'], $myBranchIds, true)));
$defaultBranchId = userDefaultBranchId($db, currentUserId());
if (!in_array($defaultBranchId, $activeIds, true)) {
    $myActive        = array_values(array_intersect($myBranchIds, $activeIds));
    $defaultBranchId = $myActive[0] ?? ($activeIds[0] ?? 0);
}
$canCreate = isAdmin() || $myBranchIds !== [];
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
<script>(function(){var t=localStorage.getItem('AB_TEL_THEME');if(t==='dark')document.documentElement.setAttribute('data-bs-theme','dark');}());</script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= assetUrl('assets/css/style.css') ?>">
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
        <button id="darkModeBtn" class="btn btn-sm btn-outline-light" title="Přepnout tmavý/světlý režim">
            <i id="darkModeIcon" class="bi bi-moon-fill"></i>
        </button>
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
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#newRequestModal"
                <?= $canCreate ? '' : 'disabled' ?>>
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
            <button class="btn btn-outline-secondary filter-btn" data-filter="new_and_mine">Nové + moje</button>
            <button class="btn btn-outline-secondary filter-btn" data-filter="in_progress">Převzaté</button>
            <button class="btn btn-outline-secondary filter-btn" data-filter="pending">Čekající</button>
            <button class="btn btn-outline-secondary filter-btn" data-filter="reopened">Znovuotevřené</button>
            <button class="btn btn-outline-secondary filter-btn" data-filter="resolved">Vyřízené</button>
            <button class="btn btn-outline-secondary filter-btn" data-filter="mine">Jen moje</button>
            <?php if (isAdmin()): ?>
            <button class="btn btn-outline-danger filter-btn" data-filter="deleted">Smazané</button>
            <?php endif; ?>
        </div>

        <!-- Pobočky (jen když uživatel vidí víc než jednu) -->
        <?php if (count($filterBranches) > 1): ?>
        <div class="btn-group btn-group-sm" role="group" aria-label="Filtr pobočky">
            <button class="btn btn-outline-secondary branch-btn active" data-branch="">Všechny</button>
            <?php foreach ($filterBranches as $b): ?>
            <button class="btn btn-outline-secondary branch-btn" data-branch="<?= $b['id'] ?>"
                    title="<?= h($b['name']) ?>"><?= h($b['code']) ?></button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

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

        <!-- Nápověda zkratek -->
        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#shortcutsModal" title="Klávesové zkratky (?)">
            <i class="bi bi-keyboard"></i>
        </button>
    </div>

    <?php if (!$canCreate): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle-fill"></i>
        Nemáte přiřazenou pobočku, kontaktujte administrátora.
    </div>
    <?php endif; ?>

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
                    <div class="mb-3">
                        <label class="form-label d-block">Pobočka, která bude požadavek řešit <span class="text-danger">*</span>
                            <span id="branchPickedName" class="branch-picked-name ms-2"></span></label>
                        <div class="d-flex align-items-center flex-wrap gap-2">
                            <div class="btn-group branch-picker-group" role="group" aria-label="Pobočka">
                                <?php foreach ($activeBranches as $b): ?>
                                <input type="radio" class="btn-check" name="branch_id" id="newReqBranch<?= $b['id'] ?>"
                                       value="<?= $b['id'] ?>" data-name="<?= h($b['name']) ?>" autocomplete="off"
                                       <?= $b['id'] === $defaultBranchId ? 'checked' : '' ?>>
                                <label class="btn btn-outline-primary" for="newReqBranch<?= $b['id'] ?>"
                                       title="<?= h($b['name']) ?>"><?= h($b['code']) ?></label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div id="branchOtherHint" class="form-text text-warning-emphasis d-none">
                            <i class="bi bi-exclamation-triangle-fill"></i> Požadavek půjde jiné pobočce než vaší domovské.
                        </div>
                    </div>
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
                    <div id="spzVehicleHint" class="d-none mb-2"></div>
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
    pageSize:        <?= getSettingInt('page_size', 50) ?>,
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
        canReopen: <?= (isAdmin() || currentUserCanReopen()) ? 'true' : 'false' ?>,
        branchIds: <?= json_encode($myBranchIds) ?>,
        defaultBranchId: <?= $defaultBranchId ?>,
        canCreate: <?= $canCreate ? 'true' : 'false' ?>
    },
    // Aktivní pobočky (výběr v novém požadavku a při přeřazení)
    branches: <?= json_encode(
        array_map(static fn (array $b): array => ['id' => $b['id'], 'code' => $b['code'], 'name' => $b['name']], $activeBranches),
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
    ) ?>,
    // Badge pobočky na kartě jen pro uživatele, kteří vidí víc poboček
    showBranch: <?= count($filterBranches) > 1 ? 'true' : 'false' ?>,
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

<!-- Modální okno: Klávesové zkratky -->
<div class="modal fade" id="shortcutsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-keyboard"></i> Klávesové zkratky</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <tr>
                            <td class="ps-3"><kbd>N</kbd></td>
                            <td class="text-muted">Nový požadavek</td>
                        </tr>
                        <tr>
                            <td class="ps-3"><kbd>/</kbd></td>
                            <td class="text-muted">Hledání</td>
                        </tr>
                        <tr>
                            <td class="ps-3"><kbd>?</kbd></td>
                            <td class="text-muted">Tato nápověda</td>
                        </tr>
                        <tr>
                            <td class="ps-3"><kbd>Esc</kbd></td>
                            <td class="text-muted">Zavřít okno</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Krátké hlášení (např. po přeřazení na jinou pobočku) -->
<div id="pageToast" class="alert alert-info page-toast d-none" role="status"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= assetUrl('assets/js/app.js') ?>"></script>
</body>
</html>
