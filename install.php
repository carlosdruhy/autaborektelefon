<?php
declare(strict_types=1);

$lockFile = __DIR__ . '/install.lock';

// ─── Kontrola install.lock ────────────────────────────────────────────────────
if (file_exists($lockFile)) {
    http_response_code(403);
    die('<h1>Instalace již proběhla.</h1><p>Soubor install.lock existuje. Pokud chcete přeinstalovat, smažte ho manuálně.</p>');
}

// ─── Kontrola PHP verze a rozšíření ──────────────────────────────────────────
$errors = [];
if (PHP_VERSION_ID < 80000) {
    $errors[] = 'PHP 8.0+ je vyžadováno. Aktuální verze: ' . PHP_VERSION;
}
if (!extension_loaded('pdo')) {
    $errors[] = 'Chybí rozšíření PDO.';
}
if (!extension_loaded('pdo_mysql')) {
    $errors[] = 'Chybí rozšíření pdo_mysql.';
}
if ($errors) {
    echo '<h1>Chyba předpokladů</h1><ul>';
    foreach ($errors as $e) {
        echo '<li>' . htmlspecialchars($e, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    echo '</ul>';
    exit;
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$message = '';
$step    = 'form'; // 'form' | 'done'

// ─── Zpracování formuláře ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminName  = trim($_POST['admin_name']  ?? '');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminPass  = $_POST['admin_pass']       ?? '';
    $adminPass2 = $_POST['admin_pass2']      ?? '';

    $formErrors = [];
    if ($adminName === '') {
        $formErrors[] = 'Jméno je povinné.';
    }
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $formErrors[] = 'Neplatný e-mail.';
    }
    if (strlen($adminPass) < 8) {
        $formErrors[] = 'Heslo musí mít alespoň 8 znaků.';
    }
    if ($adminPass !== $adminPass2) {
        $formErrors[] = 'Hesla se neshodují.';
    }

    if (!$formErrors) {
        try {
            $db = getDB();
            installSchema($db);
            insertDefaultSettings($db);
            createAdminUser($db, $adminName, $adminEmail, $adminPass);
            file_put_contents($lockFile, date('Y-m-d H:i:s'));
            $step = 'done';
        } catch (Throwable $e) {
            $message = 'Chyba instalace: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
    } else {
        $message = '<ul><li>' . implode('</li><li>', array_map(
            fn($e) => htmlspecialchars($e, ENT_QUOTES, 'UTF-8'),
            $formErrors
        )) . '</li></ul>';
    }
}

// ─── SQL schema ───────────────────────────────────────────────────────────────
function installSchema(PDO $db): void
{
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `tel_users` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(100) NOT NULL,
  `email`         VARCHAR(255) NOT NULL,
  `password_hash` VARCHAR(255) DEFAULT NULL,
  `role`          VARCHAR(20)  NOT NULL DEFAULT 'user',
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`    DATETIME     NOT NULL,
  `last_login`    DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tel_requests` (
  `id`                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `spz`               VARCHAR(20)   NOT NULL,
  `client_name`       VARCHAR(100)  NOT NULL,
  `client_phone`      VARCHAR(30)   DEFAULT NULL,
  `client_email`      VARCHAR(255)  DEFAULT NULL,
  `request_text`      TEXT          NOT NULL,
  `status`            VARCHAR(20)   NOT NULL DEFAULT 'new',
  `pending_reason`    TEXT          DEFAULT NULL,
  `reopen_reason`     TEXT          DEFAULT NULL,
  `created_by`        INT UNSIGNED  NOT NULL,
  `assigned_to_id`    INT UNSIGNED  DEFAULT NULL,
  `assigned_at`       DATETIME      DEFAULT NULL,
  `technician_note`   TEXT          DEFAULT NULL,
  `created_at`        DATETIME      NOT NULL,
  `updated_at`        DATETIME      NOT NULL,
  `resolved_at`       DATETIME      DEFAULT NULL,
  `deleted_at`        DATETIME      DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_req_status`   (`status`),
  INDEX `idx_req_created`  (`created_at`),
  INDEX `idx_req_assigned` (`assigned_to_id`),
  INDEX `idx_req_updated`  (`updated_at`),
  INDEX `idx_req_deleted`  (`deleted_at`),
  CONSTRAINT `fk_req_created_by`  FOREIGN KEY (`created_by`)     REFERENCES `tel_users`(`id`),
  CONSTRAINT `fk_req_assigned_to` FOREIGN KEY (`assigned_to_id`) REFERENCES `tel_users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tel_request_history` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id`  INT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED NOT NULL,
  `action`      VARCHAR(50)  NOT NULL,
  `field_name`  VARCHAR(50)  DEFAULT NULL,
  `old_value`   TEXT         DEFAULT NULL,
  `new_value`   TEXT         DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_hist_request`      (`request_id`),
  INDEX `idx_hist_request_time` (`request_id`, `created_at`),
  INDEX `idx_hist_time`         (`created_at`),
  CONSTRAINT `fk_hist_request` FOREIGN KEY (`request_id`) REFERENCES `tel_requests`(`id`),
  CONSTRAINT `fk_hist_user`    FOREIGN KEY (`user_id`)    REFERENCES `tel_users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tel_settings` (
  `setting_key`   VARCHAR(50) NOT NULL,
  `setting_value` TEXT        NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tel_password_resets` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `token`       VARCHAR(64)  NOT NULL,
  `created_at`  DATETIME     NOT NULL,
  `expires_at`  DATETIME     NOT NULL,
  `used_at`     DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token`),
  CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `tel_users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tel_rate_limits` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `action`       VARCHAR(30)  NOT NULL,
  `ip_address`   VARCHAR(45)  NOT NULL,
  `email`        VARCHAR(255) DEFAULT NULL,
  `attempts`     TINYINT      NOT NULL DEFAULT 0,
  `locked_until` DATETIME     DEFAULT NULL,
  `last_attempt` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_rl_action_ip`    (`action`, `ip_address`),
  INDEX `idx_rl_action_email` (`action`, `email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tel_vehicles` (
  `spz_normalized` VARCHAR(20)  NOT NULL,
  `spz_original`   VARCHAR(20)  NOT NULL,
  `vin`            VARCHAR(17)  DEFAULT NULL,
  `model`          VARCHAR(100) DEFAULT NULL,
  `updated_at`     DATETIME     NOT NULL,
  PRIMARY KEY (`spz_normalized`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

    foreach (explode(';', $sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt !== '') {
            $db->exec($stmt);
        }
    }
}

function insertDefaultSettings(PDO $db): void
{
    $db->exec("INSERT IGNORE INTO `tel_settings` (`setting_key`, `setting_value`) VALUES
        ('refresh_interval', '30'),
        ('color_level_1',    '15'),
        ('color_level_2',    '30'),
        ('color_level_3',    '60'),
        ('color_level_4',    '120'),
        ('session_timeout',  '500')");
}

function createAdminUser(PDO $db, string $name, string $email, string $password): void
{
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $now  = gmdate('Y-m-d H:i:s');
    $stmt = $db->prepare(
        'INSERT INTO tel_users (name, email, password_hash, role, is_active, created_at)
         VALUES (?, ?, ?, \'admin\', 1, ?)'
    );
    $stmt->execute([$name, $email, $hash, $now]);
}

// ─── HTML ─────────────────────────────────────────────────────────────────────
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instalace – <?= APP_NAME ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:520px">
    <h1 class="mb-4">Instalace <?= APP_NAME ?></h1>

<?php if ($step === 'done'): ?>
    <div class="alert alert-success">
        <h4>Instalace dokončena!</h4>
        <p>Admin účet byl vytvořen a databáze nastavena.</p>
        <p>Soubor <code>install.lock</code> byl vytvořen — opakovaná instalace je zablokována.</p>
        <a href="login.php" class="btn btn-primary">Přejít na přihlášení</a>
    </div>
<?php else: ?>

    <?php if ($message): ?>
        <div class="alert alert-danger"><?= $message ?></div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <h5 class="card-title mb-3">Vytvořit admin účet</h5>
            <form method="post" novalidate>
                <div class="mb-3">
                    <label for="admin_name" class="form-label">Jméno</label>
                    <input type="text" class="form-control" id="admin_name" name="admin_name"
                           value="<?= htmlspecialchars($_POST['admin_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           required>
                </div>
                <div class="mb-3">
                    <label for="admin_email" class="form-label">E-mail</label>
                    <input type="email" class="form-control" id="admin_email" name="admin_email"
                           value="<?= htmlspecialchars($_POST['admin_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           required>
                </div>
                <div class="mb-3">
                    <label for="admin_pass" class="form-label">Heslo (min. 8 znaků)</label>
                    <input type="password" class="form-control" id="admin_pass" name="admin_pass" required>
                </div>
                <div class="mb-3">
                    <label for="admin_pass2" class="form-label">Heslo znovu</label>
                    <input type="password" class="form-control" id="admin_pass2" name="admin_pass2" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Nainstalovat</button>
            </form>
        </div>
    </div>
<?php endif; ?>
</div>
</body>
</html>
