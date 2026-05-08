<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected PDO $db;

    private static array $tables = [
        'tel_sms_queue',
        'tel_password_resets',
        'tel_request_history',
        'tel_requests',
        'tel_rate_limits',
        'tel_vehicles',
        'tel_users',
        'tel_settings',
    ];

    protected function setUp(): void
    {
        $this->db = getDB();
        $this->truncateAll();
    }

    protected function tearDown(): void
    {
        // Roll back any open transaction left by a failed test
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    private function truncateAll(): void
    {
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::$tables as $table) {
            $this->db->exec("TRUNCATE TABLE `{$table}`");
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    // ─── Factory helpers ──────────────────────────────────────────────────────

    protected function createUser(
        string $name = 'Test User',
        string $role = 'user',
        string $email = ''
    ): int {
        if ($email === '') {
            $email = strtolower(str_replace(' ', '.', $name)) . uniqid('@test.') . '.example.com';
        }
        $stmt = $this->db->prepare(
            "INSERT INTO tel_users (name, email, password_hash, role, is_active, can_reopen, created_at)
             VALUES (?, ?, 'hash', ?, 1, 1, ?)"
        );
        $stmt->execute([$name, $email, $role, gmdate('Y-m-d H:i:s')]);
        return (int) $this->db->lastInsertId();
    }

    protected function createRequest(
        int $createdBy,
        string $status = 'new',
        ?string $resolvedAt = null,
        ?string $clientPhone = '777111222',
        ?string $clientEmail = 'klient@example.com',
        ?string $deletedAt = null
    ): int {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            "INSERT INTO tel_requests
               (spz, client_name, client_phone, client_email, request_text,
                status, created_by, created_at, updated_at, resolved_at, deleted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            'ABC123', 'Jan Novák', $clientPhone, $clientEmail,
            'Test požadavek', $status, $createdBy, $now, $now, $resolvedAt, $deletedAt,
        ]);
        return (int) $this->db->lastInsertId();
    }

    protected function createSms(
        int $sentBy,
        string $phone,
        string $message = 'Testovací SMS',
        string $status = 'pending',
        ?int $requestId = null
    ): int {
        $stmt = $this->db->prepare(
            "INSERT INTO tel_sms_queue (request_id, sent_by, phone, message, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$requestId, $sentBy, $phone, $message, $status, gmdate('Y-m-d H:i:s')]);
        return (int) $this->db->lastInsertId();
    }

    protected function daysAgoUtc(int $days): string
    {
        return gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));
    }
}
