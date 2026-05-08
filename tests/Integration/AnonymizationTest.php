<?php
declare(strict_types=1);

/**
 * Tests for countAnonymizable() and anonymizeRequests().
 *
 * These are the highest-risk functions in the codebase: the operation is
 * irreversible, uses a transaction + audit log, and has subtle NULL-preservation
 * logic (CASE WHEN ... IS NOT NULL). Every meaningful branch is covered here.
 */
class AnonymizationTest extends DatabaseTestCase
{
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = $this->createUser('Admin', 'admin');
    }

    // ─── countAnonymizable ────────────────────────────────────────────────────

    public function testCountReturnsZeroOnEmptyDb(): void
    {
        $this->assertSame(0, countAnonymizable(730));
    }

    public function testCountIgnoresNewRequest(): void
    {
        $this->createRequest($this->adminId, 'new');
        $this->assertSame(0, countAnonymizable(730));
    }

    public function testCountIgnoresInProgressRequest(): void
    {
        $this->createRequest($this->adminId, 'in_progress');
        $this->assertSame(0, countAnonymizable(730));
    }

    public function testCountIgnoresRecentlyResolvedRequest(): void
    {
        // Resolved 1 day ago — within the 730-day cutoff
        $this->createRequest($this->adminId, 'resolved', $this->daysAgoUtc(1));
        $this->assertSame(0, countAnonymizable(730));
    }

    public function testCountIgnoresSoftDeletedRequest(): void
    {
        $resolvedAt = $this->daysAgoUtc(800);
        $this->createRequest($this->adminId, 'resolved', $resolvedAt, '777000000', null, $this->daysAgoUtc(1));
        $this->assertSame(0, countAnonymizable(730));
    }

    public function testCountIgnoresAlreadyAnonymized(): void
    {
        $resolvedAt = $this->daysAgoUtc(800);
        $reqId = $this->createRequest($this->adminId, 'resolved', $resolvedAt);
        $this->db->exec("UPDATE tel_requests SET client_name = '[anonymizováno]' WHERE id = {$reqId}");
        $this->assertSame(0, countAnonymizable(730));
    }

    public function testCountIncludesOldResolvedRequest(): void
    {
        $this->createRequest($this->adminId, 'resolved', $this->daysAgoUtc(800));
        $this->assertSame(1, countAnonymizable(730));
    }

    public function testCountReturnsCorrectTotal(): void
    {
        $this->createRequest($this->adminId, 'resolved', $this->daysAgoUtc(800)); // qualifies
        $this->createRequest($this->adminId, 'resolved', $this->daysAgoUtc(900)); // qualifies
        $this->createRequest($this->adminId, 'resolved', $this->daysAgoUtc(10));  // too recent
        $this->createRequest($this->adminId, 'new');                              // wrong status
        $this->assertSame(2, countAnonymizable(730));
    }

    // ─── anonymizeRequests — return value ─────────────────────────────────────

    public function testAnonymizeReturnsZeroWhenNothingQualifies(): void
    {
        $this->createRequest($this->adminId, 'new');
        $this->assertSame(0, anonymizeRequests(730, $this->adminId));
    }

    public function testAnonymizeReturnsCorrectCount(): void
    {
        $this->createRequest($this->adminId, 'resolved', $this->daysAgoUtc(800));
        $this->createRequest($this->adminId, 'resolved', $this->daysAgoUtc(900));
        $this->assertSame(2, anonymizeRequests(730, $this->adminId));
    }

    // ─── anonymizeRequests — field values ────────────────────────────────────

    public function testAnonymizeSetsClientName(): void
    {
        $id = $this->createRequest($this->adminId, 'resolved', $this->daysAgoUtc(800));
        anonymizeRequests(730, $this->adminId);

        $row = $this->db->query("SELECT client_name FROM tel_requests WHERE id = {$id}")->fetch();
        $this->assertSame('[anonymizováno]', $row['client_name']);
    }

    public function testAnonymizeSetsNonNullPhone(): void
    {
        $id = $this->createRequest($this->adminId, 'resolved', $this->daysAgoUtc(800), '777123456');
        anonymizeRequests(730, $this->adminId);

        $row = $this->db->query("SELECT client_phone FROM tel_requests WHERE id = {$id}")->fetch();
        $this->assertSame('[anonymizováno]', $row['client_phone']);
    }

    public function testAnonymizePreservesNullPhone(): void
    {
        // CASE WHEN client_phone IS NOT NULL must leave NULL as NULL
        $id = $this->createRequest($this->adminId, 'resolved', $this->daysAgoUtc(800), null);
        anonymizeRequests(730, $this->adminId);

        $row = $this->db->query("SELECT client_phone FROM tel_requests WHERE id = {$id}")->fetch();
        $this->assertNull($row['client_phone']);
    }

    public function testAnonymizeSetsNonNullEmail(): void
    {
        $id = $this->createRequest($this->adminId, 'resolved', $this->daysAgoUtc(800), null, 'jan@example.com');
        anonymizeRequests(730, $this->adminId);

        $row = $this->db->query("SELECT client_email FROM tel_requests WHERE id = {$id}")->fetch();
        $this->assertSame('[anonymizováno]', $row['client_email']);
    }

    public function testAnonymizePreservesNullEmail(): void
    {
        $id = $this->createRequest($this->adminId, 'resolved', $this->daysAgoUtc(800), '777000000', null);
        anonymizeRequests(730, $this->adminId);

        $row = $this->db->query("SELECT client_email FROM tel_requests WHERE id = {$id}")->fetch();
        $this->assertNull($row['client_email']);
    }

    // ─── anonymizeRequests — scope guards ─────────────────────────────────────

    public function testAnonymizeDoesNotTouchNewRequest(): void
    {
        $id = $this->createRequest($this->adminId, 'new');
        anonymizeRequests(730, $this->adminId);

        $row = $this->db->query("SELECT client_name FROM tel_requests WHERE id = {$id}")->fetch();
        $this->assertSame('Jan Novák', $row['client_name']);
    }

    public function testAnonymizeDoesNotTouchSoftDeletedRequest(): void
    {
        $id = $this->createRequest(
            $this->adminId, 'resolved',
            $this->daysAgoUtc(800), '777000000', null,
            $this->daysAgoUtc(1)
        );
        anonymizeRequests(730, $this->adminId);

        $row = $this->db->query("SELECT client_name FROM tel_requests WHERE id = {$id}")->fetch();
        $this->assertSame('Jan Novák', $row['client_name']);
    }

    public function testAnonymizeDoesNotReAnonymize(): void
    {
        $id = $this->createRequest($this->adminId, 'resolved', $this->daysAgoUtc(800));
        $this->db->exec("UPDATE tel_requests SET client_name = '[anonymizováno]' WHERE id = {$id}");

        $count = anonymizeRequests(730, $this->adminId);
        $this->assertSame(0, $count);
    }

    public function testAnonymizeDoesNotTouchRecentlyResolved(): void
    {
        $id = $this->createRequest($this->adminId, 'resolved', $this->daysAgoUtc(1));
        anonymizeRequests(730, $this->adminId);

        $row = $this->db->query("SELECT client_name FROM tel_requests WHERE id = {$id}")->fetch();
        $this->assertSame('Jan Novák', $row['client_name']);
    }

    // ─── anonymizeRequests — audit log ────────────────────────────────────────

    public function testAnonymizeWritesAuditLogEntry(): void
    {
        $id = $this->createRequest($this->adminId, 'resolved', $this->daysAgoUtc(800));
        anonymizeRequests(730, $this->adminId);

        $row = $this->db->query(
            "SELECT action, user_id FROM tel_request_history
             WHERE request_id = {$id} AND action = 'anonymized'"
        )->fetch();

        $this->assertNotFalse($row, 'Audit log entry missing');
        $this->assertSame('anonymized', $row['action']);
        $this->assertSame($this->adminId, (int) $row['user_id']);
    }

    public function testAnonymizeWritesOneAuditEntryPerRequest(): void
    {
        $id1 = $this->createRequest($this->adminId, 'resolved', $this->daysAgoUtc(800));
        $id2 = $this->createRequest($this->adminId, 'resolved', $this->daysAgoUtc(900));
        anonymizeRequests(730, $this->adminId);

        $count = (int) $this->db->query(
            "SELECT COUNT(*) FROM tel_request_history WHERE action = 'anonymized'"
        )->fetchColumn();
        $this->assertSame(2, $count);
    }
}
