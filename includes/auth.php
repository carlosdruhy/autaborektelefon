<?php
declare(strict_types=1);

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    session_name(SESSION_NAME);
    session_start();
}

function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}

function isAdmin(): bool
{
    return isLoggedIn() && ($_SESSION['user_role'] ?? '') === 'admin';
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        if (_isApiRequest()) {
            jsonErr('Nepřihlášen', 401);
        }
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
}

function requireAdmin(): void
{
    requireLogin();
    if (!isAdmin()) {
        if (_isApiRequest()) {
            jsonErr('Nedostatečná oprávnění', 403);
        }
        header('Location: ' . APP_URL . '/dashboard.php');
        exit;
    }
}

function _isApiRequest(): bool
{
    return (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/'))
        || str_starts_with($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || !empty($_SERVER['HTTP_X_CSRF_TOKEN']);
}

function currentUserId(): int
{
    return (int)($_SESSION['user_id'] ?? 0);
}

function currentUserName(): string
{
    return (string)($_SESSION['user_name'] ?? '');
}

function currentUserRole(): string
{
    return (string)($_SESSION['user_role'] ?? '');
}

function currentUserCanReopen(): bool
{
    return (bool)($_SESSION['user_can_reopen'] ?? true);
}

/** @param array<string, mixed> $user */
function loginUser(array $user): void
{
    session_regenerate_id(true);

    $_SESSION['user_id']         = (int)$user['id'];
    $_SESSION['user_name']       = $user['name'];
    $_SESSION['user_role']       = $user['role'];
    $_SESSION['user_can_reopen'] = isset($user['can_reopen']) ? (bool)$user['can_reopen'] : true;
    $_SESSION['csrf_token']      = generateToken();
    $_SESSION['last_activity']   = time();

    $stmt = getDB()->prepare('UPDATE tel_users SET last_login = ? WHERE id = ?');
    $stmt->execute([nowUtc(), $user['id']]);
}

function checkSessionTimeout(): void
{
    if (!isLoggedIn()) {
        return;
    }

    $timeout = (int)getSetting('session_timeout', 500);
    $last    = (int)($_SESSION['last_activity'] ?? 0);

    if (time() - $last > $timeout * 60) {
        destroySession();
        if (_isApiRequest()) {
            jsonErr('Session vypršela', 401);
        }
        header('Location: ' . APP_URL . '/login.php?timeout=1');
        exit;
    }
}

function touchSession(): void
{
    $_SESSION['last_activity'] = time();
}

function destroySession(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            (string)session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
}

/** @param positive-int $bytes */
function generateToken(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

function verifyCsrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        jsonErr('Neplatný CSRF token', 403);
    }
}

/** @param array<string, mixed> $user */
function sendPasswordResetEmail(array $user, string $token): bool
{
    $resetUrl = APP_URL . '/reset-password.php?token=' . urlencode($token);
    $to       = $user['email'];
    $subject  = '=?UTF-8?B?' . base64_encode(APP_NAME . ' – nastavení hesla') . '?=';

    $body = "Dobrý den, " . $user['name'] . ",\r\n\r\n"
          . "Pro nastavení hesla klikněte na odkaz níže (platí 24 hodin):\r\n\r\n"
          . $resetUrl . "\r\n\r\n"
          . "Pokud jste tento e-mail neočekávali, ignorujte ho.\r\n\r\n"
          . "-- " . APP_NAME;

    $headers = "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n"
             . "Reply-To: " . MAIL_FROM . "\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: 8bit\r\n"
             . "X-Mailer: PHP/" . PHP_VERSION;

    return mail($to, $subject, $body, $headers);
}
