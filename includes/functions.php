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

function assetUrl(string $path): string
{
    $file = __DIR__ . '/../' . ltrim($path, '/');
    $v    = @filemtime($file) ?: 0;
    return '/' . ltrim($path, '/') . '?v=' . $v;
}

// ─── Vozidla: WMI → značka ───────────────────────────────────────────────────

function vinToBrand(string $vin): ?string
{
    if (strlen($vin) < 3) {
        return null;
    }
    static $map = [
        // Renault (Francie)
        'VF1' => 'Renault', 'VF6' => 'Renault', 'VF8' => 'Renault',
        // Citroën (Francie)
        'VF7' => 'Citroën',
        // Peugeot (Francie)
        'VF3' => 'Peugeot',
        // Dacia (Rumunsko)
        'UU1' => 'Dacia',
        // Nissan (Japonsko + Španělsko + UK Sunderland)
        'JN1' => 'Nissan', 'JN6' => 'Nissan', 'JN8' => 'Nissan', 'VSK' => 'Nissan', 'SJN' => 'Nissan',
        // Škoda (ČR)
        'TMB' => 'Škoda',
        // Volkswagen (DE)
        'WVW' => 'Volkswagen', 'WV2' => 'Volkswagen',
        // Audi (DE)
        'WAU' => 'Audi', 'WA1' => 'Audi',
        // Mercedes-Benz (DE)
        'WDB' => 'Mercedes-Benz', 'WDD' => 'Mercedes-Benz',
        'WDC' => 'Mercedes-Benz', 'WDF' => 'Mercedes-Benz',
        // Smart (DE)
        'WME' => 'Smart',
        // BMW (DE)
        'WBA' => 'BMW', 'WBY' => 'BMW', 'WBS' => 'BMW',
        // Porsche (DE)
        'WP0' => 'Porsche', 'WP1' => 'Porsche',
        // Ford Europe (DE)
        'WF0' => 'Ford',
        // Opel (DE)
        'W0L' => 'Opel',
        // SEAT (ES)
        'VSS' => 'SEAT',
        // Fiat (IT)
        'ZFA' => 'Fiat',
        // Alfa Romeo (IT)
        'ZAR' => 'Alfa Romeo',
        // Volvo (SE)
        'YV1' => 'Volvo', 'YV2' => 'Volvo',
        // Land Rover (UK)
        'SAL' => 'Land Rover',
        // Jaguar (UK)
        'SAJ' => 'Jaguar',
        // Honda (JP + UK)
        'JHM' => 'Honda', 'SHH' => 'Honda',
        // Toyota (JP)
        'JT2' => 'Toyota', 'JT3' => 'Toyota', 'JT4' => 'Toyota', 'JTD' => 'Toyota',
        // Lexus (JP)
        'JTH' => 'Lexus',
        // Hyundai (KR)
        'KMH' => 'Hyundai', 'KMF' => 'Hyundai',
        // Kia (KR + SK)
        'KNA' => 'Kia', 'KNM' => 'Kia',
        // Suzuki (JP + HU)
        'JS3' => 'Suzuki', 'TSM' => 'Suzuki',
        // Mazda (JP)
        'JMZ' => 'Mazda',
        // Mitsubishi (JP)
        'JA3' => 'Mitsubishi', 'JA4' => 'Mitsubishi',
        // Subaru (JP)
        'JF1' => 'Subaru', 'JF2' => 'Subaru',
    ];
    $wmi = strtoupper(substr($vin, 0, 3));
    return $map[$wmi] ?? null;
}

// ─── Vozidla: S3 presigned URL ────────────────────────────────────────────────

function generateS3PresignedUrl(
    string $accessKeyId,
    string $secretKey,
    string $region,
    string $bucket,
    string $objectKey,
    int    $expires = 3600
): string {
    $datetime = gmdate('Ymd\THis\Z');
    $date     = gmdate('Ymd');
    $host     = "{$bucket}.s3.{$region}.amazonaws.com";
    $scope    = "{$date}/{$region}/s3/aws4_request";
    $path     = '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($objectKey, '/'))));

    $queryParams = [
        'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
        'X-Amz-Credential'    => "{$accessKeyId}/{$scope}",
        'X-Amz-Date'          => $datetime,
        'X-Amz-Expires'       => (string) $expires,
        'X-Amz-SignedHeaders' => 'host',
    ];
    ksort($queryParams);
    $queryString = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);

    $canonicalRequest = implode("\n", [
        'GET',
        $path,
        $queryString,
        "host:{$host}\n",
        'host',
        'UNSIGNED-PAYLOAD',
    ]);

    $stringToSign = implode("\n", [
        'AWS4-HMAC-SHA256',
        $datetime,
        $scope,
        hash('sha256', $canonicalRequest),
    ]);

    $kDate    = hash_hmac('sha256', $date,          'AWS4' . $secretKey, true);
    $kRegion  = hash_hmac('sha256', $region,        $kDate,              true);
    $kService = hash_hmac('sha256', 's3',           $kRegion,            true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService,           true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    return "https://{$host}{$path}?{$queryString}&X-Amz-Signature={$signature}";
}

/**
 * HEAD request na S3 objekt — vrátí ETag a Last-Modified bez stahování obsahu.
 * @return array{etag:string,last_modified:string}|false
 */
function getS3ObjectHead(
    string $accessKeyId,
    string $secretKey,
    string $region,
    string $bucket,
    string $objectKey
): array|false {
    $datetime    = gmdate('Ymd\THis\Z');
    $date        = gmdate('Ymd');
    $host        = "{$bucket}.s3.{$region}.amazonaws.com";
    $scope       = "{$date}/{$region}/s3/aws4_request";
    $path        = '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($objectKey, '/'))));
    $payloadHash = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'; // SHA-256("")

    $canonicalHeaders = "host:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$datetime}\n";
    $signedHeaders    = 'host;x-amz-content-sha256;x-amz-date';

    $canonicalRequest = implode("\n", [
        'HEAD', $path, '', $canonicalHeaders, $signedHeaders, $payloadHash,
    ]);
    $stringToSign = implode("\n", [
        'AWS4-HMAC-SHA256', $datetime, $scope, hash('sha256', $canonicalRequest),
    ]);

    $kDate    = hash_hmac('sha256', $date,          'AWS4' . $secretKey, true);
    $kRegion  = hash_hmac('sha256', $region,        $kDate,              true);
    $kService = hash_hmac('sha256', 's3',           $kRegion,            true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService,           true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $authorization = "AWS4-HMAC-SHA256 Credential={$accessKeyId}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'HEAD',
            'timeout'       => 15,
            'ignore_errors' => true,
            'header'        => "Authorization: {$authorization}\r\nx-amz-date: {$datetime}\r\nx-amz-content-sha256: {$payloadHash}",
        ],
    ]);

    @file_get_contents("https://{$host}{$path}", false, $ctx);

    if ($http_response_header === []) {
        return false;
    }

    $etag         = '';
    $lastModified = '';
    foreach ($http_response_header as $h) {
        if (stripos($h, 'ETag:') === 0) {
            $etag = trim(substr($h, 5));
        } elseif (stripos($h, 'Last-Modified:') === 0) {
            $lastModified = trim(substr($h, 14));
        }
    }

    if ($etag === '') {
        return false;
    }

    return ['etag' => $etag, 'last_modified' => $lastModified];
}

// ─── Log v tel_settings (JSON, posledních 25 záznamů) ────────────────────────

/** @param array<string,mixed> $entry */
function appendSettingLog(string $settingKey, array $entry): void
{
    $entry['ts'] = nowUtc();
    $raw     = getSettingStr($settingKey);
    $decoded = $raw !== '' ? json_decode($raw, true) : null;
    $log     = [];
    if (is_array($decoded)) {
        foreach ($decoded as $item) {
            if (is_array($item)) {
                $log[] = $item;
            }
        }
    }
    array_unshift($log, $entry);
    $log = array_slice($log, 0, 25);
    setSetting($settingKey, (string) json_encode($log, JSON_UNESCAPED_UNICODE));
}

// ─── Vozidla: log synchronizace ──────────────────────────────────────────────

/** @param array<string,mixed> $entry */
function appendVehiclesSyncLog(array $entry): void
{
    appendSettingLog('vehicles_sync_log', $entry);
}

// ─── Záloha DB: PUT na S3 ────────────────────────────────────────────────────

function putS3Object(
    string $accessKeyId,
    string $secretKey,
    string $region,
    string $bucket,
    string $objectKey,
    string $body,
    string $contentType = 'application/octet-stream'
): bool {
    $datetime    = gmdate('Ymd\THis\Z');
    $date        = gmdate('Ymd');
    $host        = "{$bucket}.s3.{$region}.amazonaws.com";
    $scope       = "{$date}/{$region}/s3/aws4_request";
    $path        = '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($objectKey, '/'))));
    $payloadHash = hash('sha256', $body);

    $canonicalHeaders = "content-type:{$contentType}\nhost:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$datetime}\n";
    $signedHeaders    = 'content-type;host;x-amz-content-sha256;x-amz-date';

    $canonicalRequest = implode("\n", [
        'PUT', $path, '', $canonicalHeaders, $signedHeaders, $payloadHash,
    ]);
    $stringToSign = implode("\n", [
        'AWS4-HMAC-SHA256', $datetime, $scope, hash('sha256', $canonicalRequest),
    ]);

    $kDate    = hash_hmac('sha256', $date,          'AWS4' . $secretKey, true);
    $kRegion  = hash_hmac('sha256', $region,        $kDate,              true);
    $kService = hash_hmac('sha256', 's3',           $kRegion,            true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService,           true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $authorization = "AWS4-HMAC-SHA256 Credential={$accessKeyId}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

    $headers = implode("\r\n", [
        "Authorization: {$authorization}",
        "x-amz-date: {$datetime}",
        "x-amz-content-sha256: {$payloadHash}",
        "Content-Type: {$contentType}",
        'Content-Length: ' . strlen($body),
    ]);

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'PUT',
            'timeout'       => 120,
            'ignore_errors' => true,
            'content'       => $body,
            'header'        => $headers,
        ],
    ]);

    @file_get_contents("https://{$host}{$path}", false, $ctx);

    if ($http_response_header === []) {
        return false;
    }

    foreach ($http_response_header as $h) {
        if (preg_match('/^HTTP\/[\d.]+ 2\d\d/', $h)) {
            return true;
        }
    }
    return false;
}

// ─── Záloha DB: generování SQL dumpu ─────────────────────────────────────────

function generateDbDump(PDO $db): string
{
    $out  = "-- Záloha: " . gmdate('Y-m-d H:i:s') . " UTC | Auta Borek a.s. – telefon\n\n";
    $out .= "SET NAMES utf8mb4;\n";
    $out .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    $tablesStmt = $db->query('SHOW TABLES');
    if ($tablesStmt === false) {
        return $out;
    }

    /** @var list<mixed> $rawTables */
    $rawTables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($rawTables as $rawTable) {
        if (!is_string($rawTable)) {
            continue;
        }
        $table = $rawTable;

        $createStmt = $db->query('SHOW CREATE TABLE `' . str_replace('`', '', $table) . '`');
        if ($createStmt === false) {
            continue;
        }
        $createRows = pdoFetchAll($createStmt);
        if (!isset($createRows[0])) {
            continue;
        }
        $createSql = arrStr($createRows[0], 'Create Table');
        if ($createSql === '') {
            continue;
        }

        $out .= "DROP TABLE IF EXISTS `{$table}`;\n";
        $out .= "{$createSql};\n\n";

        $rowsStmt = $db->query('SELECT * FROM `' . str_replace('`', '', $table) . '`');
        if ($rowsStmt === false) {
            continue;
        }
        $rows = pdoFetchAll($rowsStmt);
        if (!$rows) {
            continue;
        }

        $cols = '`' . implode('`, `', array_keys($rows[0])) . '`';
        foreach (array_chunk($rows, 200) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                $vals = [];
                foreach ($row as $v) {
                    if ($v === null) {
                        $vals[] = 'NULL';
                    } else {
                        $q = $db->quote(is_scalar($v) ? (string) $v : '');
                        $vals[] = $q !== false ? $q : "''";
                    }
                }
                $values[] = '(' . implode(', ', $vals) . ')';
            }
            $out .= "INSERT INTO `{$table}` ({$cols}) VALUES\n" . implode(",\n", $values) . ";\n";
        }
        $out .= "\n";
    }

    $out .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    return $out;
}

// ─── Záloha DB: log záloh ────────────────────────────────────────────────────

/** @param array<string,mixed> $entry */
function appendDbBackupLog(array $entry): void
{
    appendSettingLog('db_backup_log', $entry);
}

// ─── Vozidla: import CSV ──────────────────────────────────────────────────────

/**
 * Importuje CSV soubor do tel_vehicles. Zvládá UTF-8 i Windows-1250.
 * @return array{inserted:int,updated:int,skipped:int,errors:list<string>}
 */
function importVehiclesCsv(PDO $db, string $csvPath): array
{
    $stats = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

    $raw = file_get_contents($csvPath);
    if ($raw === false) {
        $stats['errors'][] = 'Nelze přečíst soubor: ' . $csvPath;
        return $stats;
    }

    if (!mb_check_encoding($raw, 'UTF-8')) {
        $converted = iconv('windows-1250', 'UTF-8//TRANSLIT//IGNORE', $raw);
        if ($converted === false) {
            $stats['errors'][] = 'Chyba převodu kódování (Windows-1250 → UTF-8).';
            return $stats;
        }
        $raw = $converted;
    }

    $lines = explode("\n", str_replace("\r\n", "\n", $raw));

    $sql = '
        INSERT INTO tel_vehicles
            (spz_normalized, spz_original, external_id, model, year, vin, updated_at)
        VALUES
            (:spz_n, :spz_o, :ext_id, :model, :year, :vin, :updated_at)
        ON DUPLICATE KEY UPDATE
            spz_original = VALUES(spz_original),
            external_id  = VALUES(external_id),
            model        = VALUES(model),
            year         = VALUES(year),
            vin          = VALUES(vin),
            updated_at   = VALUES(updated_at)
    ';
    $stmt = $db->prepare($sql);
    $now  = nowUtc();

    $db->beginTransaction();

    foreach ($lines as $lineNo => $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $fields = str_getcsv($line, ';', '"');

        if ($lineNo === 0 && isset($fields[0]) && trim((string) $fields[0]) === 'klic') {
            continue;
        }

        if (count($fields) < 5) {
            $stats['skipped']++;
            continue;
        }

        $extId  = (int)   trim((string) ($fields[0] ?? ''));
        $spzRaw = trim((string) ($fields[1] ?? ''));
        $model  = trim((string) ($fields[2] ?? ''));
        $rokRaw = trim((string) ($fields[3] ?? ''));
        $vinRaw = trim((string) ($fields[4] ?? ''));

        $spzNorm = normalizeSpz($spzRaw);
        if ($spzNorm === '') {
            $stats['skipped']++;
            continue;
        }

        $year = null;
        if ($rokRaw !== '') {
            $y = (int) $rokRaw;
            if ($y >= 1886 && $y <= 2100) {
                $year = $y;
            }
        }

        $vin = null;
        if ($vinRaw !== '' && preg_match('/^[A-HJ-NPR-Z0-9]{17}$/i', $vinRaw)) {
            $vin = strtoupper($vinRaw);
        }

        $stmt->execute([
            ':spz_n'      => $spzNorm,
            ':spz_o'      => $spzRaw,
            ':ext_id'     => $extId > 0 ? $extId : null,
            ':model'      => $model !== '' ? $model : null,
            ':year'       => $year,
            ':vin'        => $vin,
            ':updated_at' => $now,
        ]);

        $affected = $stmt->rowCount();
        if ($affected === 1) {
            $stats['inserted']++;
        } elseif ($affected === 2) {
            $stats['updated']++;
        }
    }

    $db->commit();
    return $stats;
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

// ─── Objednávky do servisu: import CSV (planovac-objednano.csv, planovac-prijem.csv) ──

/**
 * Zdroje objednávek: klíč = hodnota sloupce tel_service_orders.source,
 * hodnoty = názvy nastavení v tel_settings a popisek pro UI.
 */
const SERVICE_ORDER_FILES = [
    'objednano' => [
        'label'         => 'Objednáno',
        'file'          => 'planovac-objednano.csv',
        'object_key'    => 's3_orders_object_key',
        'etag'          => 's3_orders_last_etag',
        'last_modified' => 's3_orders_file_last_modified',
    ],
    'prijem' => [
        'label'         => 'Příjem',
        'file'          => 'planovac-prijem.csv',
        'object_key'    => 's3_prijem_object_key',
        'etag'          => 's3_prijem_last_etag',
        'last_modified' => 's3_prijem_file_last_modified',
    ],
];

/**
 * Převede lokální čas (Europe/Prague, formát Y-m-d H:i:s) na UTC. Při neplatném vstupu vrací false.
 */
function pragueToUtc(string $local): string|false
{
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $local, new DateTimeZone('Europe/Prague'));
    if ($dt === false || $dt->format('Y-m-d H:i:s') !== $local) {
        return false;
    }
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}

/**
 * Importuje CSV s objednávkami do servisu do tel_service_orders.
 *
 * Zdroje jsou dva soubory se stejnou strukturou (datum_zac;spz;fabkod;vinkod;klient):
 * planovac-objednano.csv ($file = 'objednano') a planovac-prijem.csv ($file = 'prijem').
 * Každý je snímek aktuálního stavu, proto se vždy nahradí všechny řádky daného zdroje
 * (sloupec `source`); řádky druhého zdroje zůstávají. VIN vzniká spojením fabkod + vinkod.
 * Zvládá UTF-8 i Windows-1250.
 *
 * @return array{imported:int,skipped:int,errors:list<string>}
 */
function importServiceOrdersCsv(PDO $db, string $csvPath, string $file = 'objednano'): array
{
    $stats = ['imported' => 0, 'skipped' => 0, 'errors' => []];

    if (!array_key_exists($file, SERVICE_ORDER_FILES)) {
        $stats['errors'][] = 'Neznámý zdroj objednávek: ' . $file;
        return $stats;
    }

    $raw = file_get_contents($csvPath);
    if ($raw === false) {
        $stats['errors'][] = 'Nelze přečíst soubor: ' . $csvPath;
        return $stats;
    }

    if (!mb_check_encoding($raw, 'UTF-8')) {
        $converted = iconv('windows-1250', 'UTF-8//TRANSLIT//IGNORE', $raw);
        if ($converted === false) {
            $stats['errors'][] = 'Chyba převodu kódování (Windows-1250 → UTF-8).';
            return $stats;
        }
        $raw = $converted;
    }

    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }

    $lines = explode("\n", str_replace("\r\n", "\n", $raw));

    // Ochrana proti importu cizího souboru: první sloupec hlavičky musí být datum_zac
    $header = str_getcsv(trim($lines[0]), ';', '"', '');
    if (trim((string) ($header[0] ?? '')) !== 'datum_zac') {
        $stats['errors'][] = 'Neočekávaný formát souboru (chybí hlavička datum_zac;spz;fabkod;vinkod;klient).';
        return $stats;
    }

    $rows = [];
    foreach ($lines as $lineNo => $line) {
        $line = trim($line);
        if ($lineNo === 0 || $line === '') {
            continue;
        }

        $fields = str_getcsv($line, ';', '"', '');
        if (count($fields) < 5) {
            $stats['skipped']++;
            continue;
        }

        $dateRaw   = trim((string) ($fields[0] ?? ''));
        $spzRaw    = trim((string) ($fields[1] ?? ''));
        $fabkod    = strtoupper(trim((string) ($fields[2] ?? '')));
        $vinkod    = strtoupper(trim((string) ($fields[3] ?? '')));
        $clientRaw = trim((string) ($fields[4] ?? ''));

        $scheduledAt = pragueToUtc($dateRaw);
        if ($scheduledAt === false) {
            $stats['skipped']++;
            continue;
        }

        $spzNorm = normalizeSpz($spzRaw);

        $vin    = null;
        $vinRaw = $fabkod . $vinkod;
        if (preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vinRaw) === 1) {
            $vin = $vinRaw;
        }

        // Bez SPZ i VIN nelze objednávku k ničemu přiřadit
        if ($spzNorm === '' && $vin === null) {
            $stats['skipped']++;
            continue;
        }

        $rows[] = [
            ':scheduled_at' => $scheduledAt,
            ':spz_n'        => $spzNorm !== '' ? $spzNorm : null,
            ':spz_o'        => $spzRaw !== '' ? mb_substr($spzRaw, 0, 20) : null,
            ':vin'          => $vin,
            ':client'       => $clientRaw !== '' ? mb_substr($clientRaw, 0, 100) : null,
        ];
    }

    $stmt = $db->prepare('
        INSERT INTO tel_service_orders
            (source, scheduled_at, spz_normalized, spz_original, vin, client_name, imported_at)
        VALUES
            (:source, :scheduled_at, :spz_n, :spz_o, :vin, :client, :imported_at)
    ');
    $now = nowUtc();

    $db->beginTransaction();
    try {
        $del = $db->prepare('DELETE FROM tel_service_orders WHERE source = ?');
        $del->execute([$file]);
        foreach ($rows as $row) {
            $row[':source']      = $file;
            $row[':imported_at'] = $now;
            $stmt->execute($row);
            $stats['imported']++;
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        $stats['imported'] = 0;
        $stats['errors'][] = 'Chyba při zápisu do databáze: ' . $e->getMessage();
    }

    return $stats;
}

/** @param array<string,mixed> $entry */
function appendOrdersSyncLog(array $entry): void
{
    appendSettingLog('orders_sync_log', $entry);
}

/** Začátek dnešního dne v Praze převedený do UTC (Y-m-d H:i:s). */
function pragueTodayStartUtc(): string
{
    return pragueDayStartUtc(nowUtc());
}

/** Začátek pražského dne, do kterého spadá daný UTC okamžik, převedený zpět do UTC (Y-m-d H:i:s). */
function pragueDayStartUtc(string $utc): string
{
    $prague = new DateTimeZone('Europe/Prague');
    $dt = new DateTime($utc, new DateTimeZone('UTC'));
    $dt->setTimezone($prague);
    $dt = new DateTime($dt->format('Y-m-d') . ' 00:00:00', $prague);
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}

/**
 * Nadcházející objednávky (dnes a později) pro dané SPZ nebo VIN, seřazené podle termínu.
 * @param list<string> $spzs
 * @param list<string> $vins
 * @return list<array{spz:string,vin:string,scheduled_at:string,scheduled_at_local:string,client_name:string,source:string}>
 */
function getUpcomingServiceOrders(PDO $db, array $spzs, array $vins): array
{
    $spzs = array_values(array_unique(array_filter($spzs, static fn (string $s): bool => $s !== '')));
    $vins = array_values(array_unique(array_filter($vins, static fn (string $v): bool => $v !== '')));
    if ($spzs === [] && $vins === []) {
        return [];
    }

    $conds  = [];
    $params = [pragueTodayStartUtc()];
    if ($spzs !== []) {
        $conds[] = 'spz_normalized IN (' . implode(',', array_fill(0, count($spzs), '?')) . ')';
        array_push($params, ...$spzs);
    }
    if ($vins !== []) {
        $conds[] = 'vin IN (' . implode(',', array_fill(0, count($vins), '?')) . ')';
        array_push($params, ...$vins);
    }

    $stmt = $db->prepare(
        'SELECT spz_normalized, vin, scheduled_at, client_name, source
         FROM tel_service_orders
         WHERE scheduled_at >= ? AND (' . implode(' OR ', $conds) . ')
         ORDER BY scheduled_at ASC, source ASC'
    );
    $stmt->execute($params);

    $out = [];
    foreach (pdoFetchAll($stmt) as $o) {
        $out[] = [
            'spz'                => arrStr($o, 'spz_normalized'),
            'vin'                => arrStr($o, 'vin'),
            'scheduled_at'       => arrStr($o, 'scheduled_at'),
            'scheduled_at_local' => toLocalTime(arrStr($o, 'scheduled_at')),
            'client_name'        => arrStr($o, 'client_name'),
            'source'             => arrStr($o, 'source'),
        ];
    }
    return $out;
}

/**
 * Vybere z předem načtených objednávek ty, které patří k dané SPZ / VIN.
 * U vyřízeného požadavku ($resolvedAtUtc) jen objednávky s datem >= datum vyřízení (pražský den).
 * Stejný termín pro stejné vozidlo ve více zdrojích (objednáno i příjem) se vrací jen jednou
 * (přednost má 'objednano' díky řazení podle source).
 * @param list<array{spz:string,vin:string,scheduled_at:string,scheduled_at_local:string,client_name:string,source:string}> $orders
 * @return list<array{scheduled_at_local:string,client_name:string,source:string}>
 */
function matchServiceOrders(array $orders, string $spz, string $vin, ?string $resolvedAtUtc = null): array
{
    $minScheduledAt = $resolvedAtUtc !== null ? pragueDayStartUtc($resolvedAtUtc) : '';
    $out  = [];
    $seen = [];
    foreach ($orders as $o) {
        if ($o['scheduled_at'] < $minScheduledAt) {
            continue;
        }
        if (($spz !== '' && $o['spz'] === $spz) || ($vin !== '' && $o['vin'] === $vin)) {
            $key = $o['scheduled_at'] . '|' . $o['spz'] . '|' . $o['vin'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'scheduled_at_local' => $o['scheduled_at_local'],
                'client_name'        => $o['client_name'],
                'source'             => $o['source'],
            ];
        }
    }
    return $out;
}

// ─── S3 synchronizace: společná logika pro cron i ruční spuštění ─────────────

/**
 * Stáhne CSV ze S3 a předá ho importní funkci. Při shodném ETagu (a $force === false) se nic nestahuje.
 *
 * @param callable(PDO, string): array<string, mixed> $importer  importVehiclesCsv / importServiceOrdersCsv
 * @return array{status:string,message:string,etag:string,last_modified:string,import:array<string,mixed>}
 *         status: not_configured | head_failed | skipped | download_failed | tmp_failed | import_error | synced
 */
function fetchAndImportS3Csv(string $objectKey, string $lastEtag, bool $force, callable $importer, string $tmpPrefix): array
{
    $out = ['status' => '', 'message' => '', 'etag' => '', 'last_modified' => '', 'import' => []];

    $accessKeyId = getSettingStr('s3_access_key_id');
    $secretKey   = getSettingStr('s3_secret_access_key');
    $region      = getSettingStr('s3_region');
    $bucket      = getSettingStr('s3_bucket');

    if ($accessKeyId === '' || $secretKey === '' || $region === '' || $bucket === '' || $objectKey === '') {
        $out['status']  = 'not_configured';
        $out['message'] = 'S3 není nakonfigurováno';
        return $out;
    }

    // HEAD request — zjisti ETag bez stahování celého souboru
    $head = getS3ObjectHead($accessKeyId, $secretKey, $region, $bucket, $objectKey);
    if ($head === false) {
        $out['status']  = 'head_failed';
        $out['message'] = 'HEAD selhal — přihlašovací údaje nebo cesta?';
        return $out;
    }
    $out['etag']          = $head['etag'];
    $out['last_modified'] = $head['last_modified'];

    if (!$force && $lastEtag !== '' && $head['etag'] === $lastEtag) {
        $out['status'] = 'skipped';
        return $out;
    }

    // Presigned URL platná 5 minut a stažení CSV
    $url = generateS3PresignedUrl($accessKeyId, $secretKey, $region, $bucket, $objectKey, 300);
    $ctx = stream_context_create(['http' => ['timeout' => 60, 'follow_location' => true]]);
    $csv = @file_get_contents($url, false, $ctx);
    if ($csv === false) {
        $out['status']  = 'download_failed';
        $out['message'] = 'Stahování ze S3 selhalo';
        return $out;
    }

    $tmp = tempnam(sys_get_temp_dir(), $tmpPrefix);
    if ($tmp === false) {
        $out['status']  = 'tmp_failed';
        $out['message'] = 'Nelze vytvořit dočasný soubor';
        return $out;
    }

    try {
        file_put_contents($tmp, $csv);
        $result = $importer(getDB(), $tmp);
    } finally {
        @unlink($tmp);
    }

    $out['import'] = $result;
    $errors = $result['errors'] ?? [];
    if (is_array($errors) && $errors !== []) {
        $out['status']  = 'import_error';
        $out['message'] = implode('; ', array_map(static fn (mixed $e): string => is_string($e) ? $e : '', $errors));
        return $out;
    }

    $out['status'] = 'synced';
    return $out;
}

/**
 * Synchronizace vozidel (export-spz.csv → tel_vehicles) včetně zápisu nastavení a protokolu.
 * @param string $source 'cron' | 'manual' (ruční spuštění ignoruje ETag a stahuje vždy)
 * @return array{status:string,message:string,inserted:int,updated:int,skipped:int,etag:string,last_modified:string}
 */
function syncVehiclesFromS3(string $source): array
{
    $r = fetchAndImportS3Csv(
        getSettingStr('s3_object_key'),
        getSettingStr('s3_last_etag'),
        $source === 'manual',
        'importVehiclesCsv',
        'veh_'
    );
    $inserted = arrInt($r['import'], 'inserted');
    $updated  = arrInt($r['import'], 'updated');
    $skipped  = arrInt($r['import'], 'skipped');

    if ($r['status'] === 'synced') {
        setSetting('vehicles_last_sync', nowUtc());
        setSetting('vehicles_last_sync_count', (string) ($inserted + $updated));
        setSetting('s3_last_etag', $r['etag']);
        setSetting('s3_file_last_modified', $r['last_modified']);
        appendVehiclesSyncLog([
            'source' => $source, 'result' => 'synced',
            'inserted' => $inserted, 'updated' => $updated, 'skipped_rows' => $skipped,
        ]);
    } elseif ($r['status'] === 'skipped') {
        appendVehiclesSyncLog(['source' => $source, 'result' => 'skipped', 'etag' => $r['etag']]);
    } else {
        appendVehiclesSyncLog(['source' => $source, 'result' => 'error', 'message' => $r['message']]);
    }

    return [
        'status' => $r['status'], 'message' => $r['message'],
        'inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped,
        'etag' => $r['etag'], 'last_modified' => $r['last_modified'],
    ];
}

/**
 * Synchronizace jednoho zdroje objednávek (klíč z SERVICE_ORDER_FILES) → tel_service_orders,
 * včetně zápisu nastavení a protokolu.
 * @param string $source 'cron' | 'manual'
 * @return array{status:string,message:string,imported:int,skipped:int,etag:string,last_modified:string}
 */
function syncServiceOrdersFromS3(string $source, string $file = 'objednano'): array
{
    $cfg = SERVICE_ORDER_FILES[$file] ?? SERVICE_ORDER_FILES['objednano'];
    $r = fetchAndImportS3Csv(
        getSettingStr($cfg['object_key']),
        getSettingStr($cfg['etag']),
        $source === 'manual',
        static fn (PDO $db, string $path): array => importServiceOrdersCsv($db, $path, $file),
        'ord_'
    );
    $imported = arrInt($r['import'], 'imported');
    $skipped  = arrInt($r['import'], 'skipped');

    if ($r['status'] === 'synced') {
        $countStmt = getDB()->query('SELECT COUNT(*) FROM tel_service_orders');
        $total     = $countStmt !== false ? (int) $countStmt->fetchColumn() : 0;
        setSetting('orders_last_sync', nowUtc());
        setSetting('orders_last_sync_count', (string) $total);
        setSetting($cfg['etag'], $r['etag']);
        setSetting($cfg['last_modified'], $r['last_modified']);
        appendOrdersSyncLog([
            'source' => $source, 'file' => $file, 'result' => 'synced',
            'imported' => $imported, 'skipped_rows' => $skipped,
        ]);
    } elseif ($r['status'] === 'skipped') {
        appendOrdersSyncLog(['source' => $source, 'file' => $file, 'result' => 'skipped', 'etag' => $r['etag']]);
    } else {
        appendOrdersSyncLog(['source' => $source, 'file' => $file, 'result' => 'error', 'message' => $r['message']]);
    }

    return [
        'status' => $r['status'], 'message' => $r['message'],
        'imported' => $imported, 'skipped' => $skipped,
        'etag' => $r['etag'], 'last_modified' => $r['last_modified'],
    ];
}

/**
 * Synchronizuje všechny nakonfigurované zdroje objednávek. Zdroje bez nastavené cesty se přeskočí.
 * @return array<string, array{status:string,message:string,imported:int,skipped:int,etag:string,last_modified:string}>
 */
function syncAllServiceOrdersFromS3(string $source): array
{
    $out = [];
    foreach (SERVICE_ORDER_FILES as $file => $cfg) {
        if (getSettingStr($cfg['object_key']) === '') {
            $out[$file] = [
                'status' => 'not_configured', 'message' => 'Cesta k souboru není nastavena',
                'imported' => 0, 'skipped' => 0, 'etag' => '', 'last_modified' => '',
            ];
            continue;
        }
        $out[$file] = syncServiceOrdersFromS3($source, $file);
    }
    return $out;
}
