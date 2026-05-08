<?php
declare(strict_types=1);

/**
 * Tests for tel_sms_queue DB operations and the interaction between
 * the SMS queue and the anonymization logic.
 *
 * There is no dedicated enqueueSms() function — the API handler does it
 * inline — so these tests exercise the schema and query patterns directly,
 * plus one critical cross-cutting concern: anonymizeRequests() must NOT
 * touch phone numbers stored in the SMS queue.
 */
class SmsQueueTest extends DatabaseTestCase
{
    private int $userId;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId  = $this->createUser('Technik');
        $this->adminId = $this->createUser('Admin', 'admin');
    }

    // ─── Basic CRUD ───────────────────────────────────────────────────────────

    public function testInsertedSmsAppearsAsPending(): void
    {
        $reqId = $this->createRequest($this->userId);
        $smsId = $this->createSms($this->userId, '777123456', 'Ahoj', 'pending', $reqId);

        $row = $this->db->query(
            "SELECT status, phone FROM tel_sms_queue WHERE id = {$smsId}"
        )->fetch();

        $this->assertNotFalse($row);
        $this->assertSame('pending', $row['status']);
        $this->assertSame('777123456', $row['phone']);
    }

    public function testSmsCanBeMarkedAsSent(): void
    {
        $reqId = $this->createRequest($this->userId);
        $smsId = $this->createSms($this->userId, '777123456', 'Ahoj', 'pending', $reqId);

        $sentAt = gmdate('Y-m-d H:i:s');
        $this->db->exec(
            "UPDATE tel_sms_queue SET status = 'sent', sent_at = '{$sentAt}' WHERE id = {$smsId}"
        );

        $row = $this->db->query(
            "SELECT status, sent_at FROM tel_sms_queue WHERE id = {$smsId}"
        )->fetch();
        $this->assertSame('sent', $row['status']);
        $this->assertNotNull($row['sent_at']);
    }

    public function testSmsCanBeMarkedAsFailed(): void
    {
        $reqId = $this->createRequest($this->userId);
        $smsId = $this->createSms($this->userId, '777123456', 'Ahoj', 'pending', $reqId);

        $this->db->exec(
            "UPDATE tel_sms_queue SET status = 'failed', error_msg = 'Timeout' WHERE id = {$smsId}"
        );

        $row = $this->db->query(
            "SELECT status, error_msg FROM tel_sms_queue WHERE id = {$smsId}"
        )->fetch();
        $this->assertSame('failed', $row['status']);
        $this->assertSame('Timeout', $row['error_msg']);
    }

    // ─── SMS without a linked request ─────────────────────────────────────────

    public function testSmsWithNullRequestIdIsAllowed(): void
    {
        $smsId = $this->createSms($this->userId, '777000000', 'Test', 'pending', null);

        $row = $this->db->query(
            "SELECT request_id FROM tel_sms_queue WHERE id = {$smsId}"
        )->fetch();
        $this->assertNull($row['request_id']);
    }

    // ─── Counting SMS per request ─────────────────────────────────────────────

    public function testSmsCountQueryReturnsCorrectTotal(): void
    {
        $reqId = $this->createRequest($this->userId);
        $this->createSms($this->userId, '777111111', 'Msg 1', 'sent', $reqId);
        $this->createSms($this->userId, '777111111', 'Msg 2', 'sent', $reqId);
        $this->createSms($this->userId, '777111111', 'Msg 3', 'failed', $reqId);

        $count = (int) $this->db->query(
            "SELECT COUNT(*) FROM tel_sms_queue WHERE request_id = {$reqId}"
        )->fetchColumn();
        $this->assertSame(3, $count);
    }

    public function testPendingSmsQueryIsFilterable(): void
    {
        $reqId = $this->createRequest($this->userId);
        $this->createSms($this->userId, '777111111', 'Pending', 'pending', $reqId);
        $this->createSms($this->userId, '777111111', 'Sent',    'sent',    $reqId);

        $count = (int) $this->db->query(
            "SELECT COUNT(*) FROM tel_sms_queue WHERE status = 'pending'"
        )->fetchColumn();
        $this->assertSame(1, $count);
    }

    // ─── Anonymization does NOT touch SMS queue ───────────────────────────────

    public function testAnonymizationLeavesSmsPhonesIntact(): void
    {
        $reqId = $this->createRequest(
            $this->adminId, 'resolved',
            $this->daysAgoUtc(800), '777123456'
        );
        $phone = '777123456';
        $this->createSms($this->userId, $phone, 'Zpráva', 'sent', $reqId);

        anonymizeRequests(730, $this->adminId);

        // Request phone anonymized
        $reqRow = $this->db->query(
            "SELECT client_phone FROM tel_requests WHERE id = {$reqId}"
        )->fetch();
        $this->assertSame('[anonymizováno]', $reqRow['client_phone']);

        // SMS phone untouched — current design; document expected behavior
        $smsRow = $this->db->query(
            "SELECT phone FROM tel_sms_queue WHERE request_id = {$reqId}"
        )->fetch();
        $this->assertSame($phone, $smsRow['phone'],
            'SMS queue phone is intentionally NOT anonymized by anonymizeRequests()');
    }
}
