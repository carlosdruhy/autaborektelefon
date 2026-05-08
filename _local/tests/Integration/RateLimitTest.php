<?php
declare(strict_types=1);

/**
 * Tests for checkRateLimit() and recordRateFail().
 *
 * Rate limiting is the only brute-force protection in the application.
 * Login allows 5 attempts before locking; password-reset allows 3.
 * Lockout duration doubles with each additional failure (exponential backoff).
 */
class RateLimitTest extends DatabaseTestCase
{
    private const IP  = '192.168.1.100';
    private const IP2 = '10.0.0.1';

    // ─── checkRateLimit — baseline ────────────────────────────────────────────

    public function testAllowsUnknownIp(): void
    {
        $this->assertTrue(checkRateLimit('login', self::IP, ''));
    }

    public function testAllowsWithEmailWhenNoRecord(): void
    {
        $this->assertTrue(checkRateLimit('login', self::IP, 'user@example.com'));
    }

    // ─── recordRateFail — record creation ─────────────────────────────────────

    public function testFirstFailCreatesRecord(): void
    {
        recordRateFail('login', self::IP, '');

        $row = $this->db->query(
            "SELECT attempts, locked_until FROM tel_rate_limits
             WHERE action = 'login' AND ip_address = '" . self::IP . "' AND email IS NULL"
        )->fetch();

        $this->assertNotFalse($row);
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertNull($row['locked_until']);
    }

    public function testSubsequentFailsIncrementAttempts(): void
    {
        recordRateFail('login', self::IP, '');
        recordRateFail('login', self::IP, '');
        recordRateFail('login', self::IP, '');

        $attempts = (int) $this->db->query(
            "SELECT attempts FROM tel_rate_limits WHERE action = 'login' AND ip_address = '" . self::IP . "'"
        )->fetchColumn();

        $this->assertSame(3, $attempts);
    }

    // ─── login: 5-attempt threshold ───────────────────────────────────────────

    public function testFourFailsDoNotLockLogin(): void
    {
        for ($i = 0; $i < 4; $i++) {
            recordRateFail('login', self::IP, '');
        }
        $this->assertTrue(checkRateLimit('login', self::IP, ''));
    }

    public function testFiveFailsLockLogin(): void
    {
        for ($i = 0; $i < 5; $i++) {
            recordRateFail('login', self::IP, '');
        }
        $this->assertFalse(checkRateLimit('login', self::IP, ''));
    }

    public function testLockedIpIsBlockedImmediatelyAfter(): void
    {
        // Seed a lock directly to avoid timing issues
        $futureTs = gmdate('Y-m-d H:i:s', time() + 900);
        $this->db->exec(
            "INSERT INTO tel_rate_limits (action, ip_address, email, attempts, locked_until, last_attempt)
             VALUES ('login', '" . self::IP . "', NULL, 5, '{$futureTs}', NOW())"
        );
        $this->assertFalse(checkRateLimit('login', self::IP, ''));
    }

    // ─── lock expiry ──────────────────────────────────────────────────────────

    public function testExpiredLockAllowsAccess(): void
    {
        $pastTs = gmdate('Y-m-d H:i:s', time() - 1);
        $this->db->exec(
            "INSERT INTO tel_rate_limits (action, ip_address, email, attempts, locked_until, last_attempt)
             VALUES ('login', '" . self::IP . "', NULL, 5, '{$pastTs}', NOW())"
        );
        $this->assertTrue(checkRateLimit('login', self::IP, ''));
    }

    // ─── reset: 3-attempt threshold ───────────────────────────────────────────

    public function testTwoResetFailsDoNotLock(): void
    {
        recordRateFail('reset', self::IP, 'user@example.com');
        recordRateFail('reset', self::IP, 'user@example.com');
        $this->assertTrue(checkRateLimit('reset', self::IP, 'user@example.com'));
    }

    public function testThreeResetFailsLock(): void
    {
        for ($i = 0; $i < 3; $i++) {
            recordRateFail('reset', self::IP, 'user@example.com');
        }
        $this->assertFalse(checkRateLimit('reset', self::IP, 'user@example.com'));
    }

    // ─── isolation: different IPs ─────────────────────────────────────────────

    public function testDifferentIpsAreTrackedIndependently(): void
    {
        // Lock IP1
        for ($i = 0; $i < 5; $i++) {
            recordRateFail('login', self::IP, '');
        }

        // IP2 should still be allowed
        $this->assertTrue(checkRateLimit('login', self::IP2, ''));
    }

    // ─── isolation: email vs no-email ────────────────────────────────────────

    public function testEmailAndBlankEmailAreTrackedSeparately(): void
    {
        // Lock the blank-email slot for IP
        for ($i = 0; $i < 5; $i++) {
            recordRateFail('login', self::IP, '');
        }

        // A named email on the same IP should still be allowed
        $this->assertTrue(checkRateLimit('login', self::IP, 'other@example.com'));
    }

    // ─── exponential backoff ──────────────────────────────────────────────────

    public function testSixthFailDoublesLockoutDuration(): void
    {
        // Trigger standard lock at 5 fails
        for ($i = 0; $i < 5; $i++) {
            recordRateFail('login', self::IP, '');
        }
        $lockedAfter5 = (string) $this->db->query(
            "SELECT locked_until FROM tel_rate_limits WHERE action = 'login' AND ip_address = '" . self::IP . "'"
        )->fetchColumn();

        // 6th fail — multiplier doubles
        recordRateFail('login', self::IP, '');
        $lockedAfter6 = (string) $this->db->query(
            "SELECT locked_until FROM tel_rate_limits WHERE action = 'login' AND ip_address = '" . self::IP . "'"
        )->fetchColumn();

        $ts5 = strtotime($lockedAfter5);
        $ts6 = strtotime($lockedAfter6);

        // locked_until after 6th fail must be further in the future than after 5th
        $this->assertGreaterThan($ts5, $ts6, '6th fail should extend lockout further than 5th');
    }
}
