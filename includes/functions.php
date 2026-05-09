<?php
declare(strict_types=1);

// ─── Settings ────────────────────────────────────────────────────────────────

/** @return array<string, string> */
function getSettings(): array
{
    $stmt = getDB()->query('SELECT setting_key, setting_value FROM tel_settings') ?: throw new \RuntimeException('Query failed');
    $rows = $stmt->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $out[$row['setting_key']] = $row['setting_value'];
    }
    return $out;
}

function getSetting(string $key, mixed $default = null): mixed
{
    $stmt = getDB()->prepare('SELECT setting_value FROM tel_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $row = pdoFetch($stmt);
    return $row !== false ? $row['setting_value'] : $default;
}

function setSetting(string $key, string $value): void
{
    $stmt = getDB()->prepare('REPLACE INTO tel_settings (setting_key, setting_value) VALUES (?, ?)');
    $stmt->execute([$key, $value]);
}

// ─── HTTP / JSON ──────────────────────────────────────────────────────────────

function jsonOk(mixed $data = null): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

/** @param array<string, mixed> $extra */
function jsonErr(string $msg, int $code = 400, array $extra = []): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => false, 'error' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

/** @return array<string, mixed> */
function getPostedJson(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

// ─── Výstup ───────────────────────────────────────────────────────────────────

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// ─── Čas ─────────────────────────────────────────────────────────────────────

function nowUtc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function toLocalTime(string $utc): string
{
    $dt = new DateTime($utc, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone('Europe/Prague'));
    return $dt->format('d.m.Y H:i');
}

function ageMinutes(string $utcDatetime): int
{
    $created = new DateTime($utcDatetime, new DateTimeZone('UTC'));
    $now     = new DateTime('now', new DateTimeZone('UTC'));
    $diff    = $now->getTimestamp() - $created->getTimestamp();
    return (int) max(0, floor($diff / 60));
}

// ─── SPZ ──────────────────────────────────────────────────────────────────────

function normalizeSpz(string $spz): string
{
    $spz = preg_replace('/[\s\-]/', '', $spz);
    return strtoupper($spz ?? '');
}

// ─── Audit log ───────────────────────────────────────────────────────────────

function truncateForLog(string $s, int $max = 500): string
{
    if (mb_strlen($s) <= $max) {
        return $s;
    }
    $suffix = '[zkráceno]';
    return mb_substr($s, 0, max(0, $max - mb_strlen($suffix))) . $suffix;
}

function logAudit(
    PDO $db,
    int $requestId,
    int $userId,
    string $action,
    ?string $field = null,
    ?string $old = null,
    ?string $new = null
): void {
    $stmt = $db->prepare(
        'INSERT INTO tel_request_history
            (request_id, user_id, action, field_name, old_value, new_value, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $requestId,
        $userId,
        $action,
        $field,
        $old !== null ? truncateForLog($old) : null,
        $new !== null ? truncateForLog($new) : null,
        nowUtc(),
    ]);
}

// ─── Rate limiting ────────────────────────────────────────────────────────────

function checkRateLimit(string $action, string $ip, string $email = ''): bool
{
    $db   = getDB();
    $now  = nowUtc();

    // Hledáme záznam dle kombinace action + ip + email
    $stmt = $db->prepare(
        'SELECT id, attempts, locked_until
         FROM tel_rate_limits
         WHERE action = ? AND ip_address = ? AND (email = ? OR (email IS NULL AND ? = \'\'))
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$action, $ip, $email, $email]);
    $row = pdoFetch($stmt);

    if (!$row) {
        return true; // Žádný záznam = povoleno
    }

    $lockedUntil = arrStrNull($row, 'locked_until');
    if ($lockedUntil !== null && $lockedUntil > $now) {
        return false; // Stále zamknuto
    }

    return true;
}

function recordRateFail(string $action, string $ip, string $email = ''): void
{
    $db  = getDB();
    $now = nowUtc();

    $stmt = $db->prepare(
        'SELECT id, attempts, locked_until
         FROM tel_rate_limits
         WHERE action = ? AND ip_address = ? AND (email = ? OR (email IS NULL AND ? = \'\'))
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$action, $ip, $email, $email]);
    $row = pdoFetch($stmt);

    // Limity dle akce
    $limits = [
        'login' => ['max' => 5, 'window_min' => 15, 'lockout_min' => 15],
        'reset' => ['max' => 3, 'window_min' => 60, 'lockout_min' => 60],
    ];
    $cfg = $limits[$action] ?? ['max' => 5, 'window_min' => 15, 'lockout_min' => 15];

    if (!$row) {
        // První pokus
        $stmt = $db->prepare(
            'INSERT INTO tel_rate_limits (action, ip_address, email, attempts, last_attempt)
             VALUES (?, ?, ?, 1, ?)'
        );
        $stmt->execute([$action, $ip, $email ?: null, $now]);
        return;
    }

    $attempts  = arrInt($row, 'attempts') + 1;
    $lockedUntil = null;

    if ($attempts >= $cfg['max']) {
        // Exponential backoff: každý další pokus zdvojnásobí lockout
        $multiplier  = max(1, $attempts - $cfg['max'] + 1);
        $lockoutMins = $cfg['lockout_min'] * $multiplier;
        $lockedUntil = gmdate('Y-m-d H:i:s', time() + $lockoutMins * 60);
    }

    $rowId = arrInt($row, 'id');
    if ($rowId > 0) {
        $stmt = $db->prepare(
            'UPDATE tel_rate_limits
             SET attempts = ?, locked_until = ?, last_attempt = ?
             WHERE id = ?'
        );
        $stmt->execute([$attempts, $lockedUntil, $now, $rowId]);
    }
}

// ─── GDPR anonymizace ────────────────────────────────────────────────────────

function countAnonymizable(int $days): int
{
    $cutoff = gmdate('Y-m-d H:i:s', (int)strtotime("-{$days} days"));
    $stmt = getDB()->prepare(
        "SELECT COUNT(*) FROM tel_requests
         WHERE status = 'resolved'
           AND resolved_at IS NOT NULL
           AND resolved_at < ?
           AND deleted_at IS NULL
           AND client_name != '[anonymizováno]'"
    );
    $stmt->execute([$cutoff]);
    return (int) $stmt->fetchColumn();
}

function anonymizeRequests(int $days, int $adminId): int
{
    $db     = getDB();
    $cutoff = gmdate('Y-m-d H:i:s', (int)strtotime("-{$days} days"));

    $stmt = $db->prepare(
        "SELECT id FROM tel_requests
         WHERE status = 'resolved'
           AND resolved_at IS NOT NULL
           AND resolved_at < ?
           AND deleted_at IS NULL
           AND client_name != '[anonymizováno]'"
    );
    $stmt->execute([$cutoff]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($ids)) {
        return 0;
    }

    $db->beginTransaction();
    try {
        $update = $db->prepare(
            "UPDATE tel_requests
             SET client_name  = '[anonymizováno]',
                 client_phone = CASE WHEN client_phone IS NOT NULL THEN '[anonymizováno]' ELSE NULL END,
                 client_email = CASE WHEN client_email IS NOT NULL THEN '[anonymizováno]' ELSE NULL END
             WHERE id = ?"
        );
        foreach ($ids as $id) {
            $update->execute([(int) $id]);
            logAudit($db, (int) $id, $adminId, 'anonymized');
        }
        $db->commit();
        return count($ids);
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

// ─── Logování chyb aplikace ───────────────────────────────────────────────────

function appLog(string $message): void
{
    $line = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message);
    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

// ─── PDO helpers ─────────────────────────────────────────────────────────────

/** @return array<string, mixed>|false */
function pdoFetch(PDOStatement $stmt): array|false
{
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return false;
    $typed = [];
    foreach ($row as $k => $v) {
        if (is_string($k)) {
            $typed[$k] = $v;
        }
    }
    return $typed;
}

/** @return list<array<string, mixed>> */
function pdoFetchAll(PDOStatement $stmt): array
{
    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!is_array($row)) continue;
        $typed = [];
        foreach ($row as $k => $v) {
            if (is_string($k)) {
                $typed[$k] = $v;
            }
        }
        $result[] = $typed;
    }
    return $result;
}

// ─── Typované přístupy ke smíšeným polím ─────────────────────────────────────

/** @param array<int|string, mixed> $arr */
function arrStr(array $arr, int|string $key, string $default = ''): string
{
    $v = $arr[$key] ?? null;
    return is_string($v) ? $v : $default;
}

/** @param array<int|string, mixed> $arr */
function arrInt(array $arr, int|string $key, int $default = 0): int
{
    $v = $arr[$key] ?? null;
    if (is_int($v)) return $v;
    if (is_numeric($v)) return (int)$v;
    return $default;
}

/** @param array<int|string, mixed> $arr */
function arrStrNull(array $arr, int|string $key): ?string
{
    $v = $arr[$key] ?? null;
    return is_string($v) ? $v : null;
}

function getSettingStr(string $key, string $default = ''): string
{
    $v = getSetting($key, $default);
    return is_string($v) ? $v : $default;
}

function getSettingInt(string $key, int $default = 0): int
{
    $v = getSetting($key, $default);
    if (is_int($v)) return $v;
    if (is_numeric($v)) return (int)$v;
    return $default;
}
