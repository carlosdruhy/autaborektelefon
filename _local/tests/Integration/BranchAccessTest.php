<?php
declare(strict_types=1);

/**
 * Pobočky (PRD 3.14): kdo vidí který požadavek a přeřazení na jinou pobočku.
 * Bezpečnostně kritické — chyba by znamenala, že uživatel vidí nebo mění požadavky cizí pobočky.
 */
final class BranchAccessTest extends DatabaseTestCase
{
    private int $borek;
    private int $tabor;
    private int $userBorek;
    private int $userTabor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->borek     = $this->defaultBranchId;
        $this->tabor     = $this->createBranch('TAB', 'Tábor – servis', '33');
        $this->userBorek = $this->createUser('Recepce Borek');
        $this->userTabor = $this->createUser('Technik Tábor');
        $this->assignUserToBranch($this->userBorek, $this->borek);
        $this->assignUserToBranch($this->userTabor, $this->tabor);
    }

    /** @return array<string, mixed> */
    private function fetchRequest(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM tel_requests WHERE id = ?');
        $stmt->execute([$id]);
        $row = pdoFetch($stmt);
        $this->assertNotFalse($row);
        return $row;
    }

    /** @return list<int> ID požadavků, které uživatel uvidí ve výpisu (stejná podmínka jako api/requests.php) */
    private function visibleIds(int $userId): array
    {
        [$cond, $params] = branchVisibilityCondition(userBranchIds($this->db, $userId), $userId);
        $stmt = $this->db->prepare("SELECT r.id FROM tel_requests r WHERE r.deleted_at IS NULL AND $cond ORDER BY r.id");
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // ─── Úroveň přístupu ──────────────────────────────────────────────────────

    public function testMemberOfBranchHasFullAccess(): void
    {
        $req = $this->fetchRequest($this->createRequest($this->userTabor, 'new', branchId: $this->borek));
        $this->assertSame('full', requestAccessLevel($req, $this->userBorek, false, [$this->borek]));
    }

    public function testAuthorFromOtherBranchHasCreatorAccess(): void
    {
        $req = $this->fetchRequest($this->createRequest($this->userBorek, 'new', branchId: $this->tabor));
        $this->assertSame('creator', requestAccessLevel($req, $this->userBorek, false, [$this->borek]));
    }

    public function testOtherUserHasNoAccess(): void
    {
        $req = $this->fetchRequest($this->createRequest($this->userTabor, 'new', branchId: $this->tabor));
        $this->assertSame('none', requestAccessLevel($req, $this->userBorek, false, [$this->borek]));
    }

    public function testAdminHasFullAccessEverywhere(): void
    {
        $req = $this->fetchRequest($this->createRequest($this->userTabor, 'new', branchId: $this->tabor));
        $this->assertSame('full', requestAccessLevel($req, 999, true, []));
    }

    public function testUserWithoutBranchHasNoAccess(): void
    {
        $nobody = $this->createUser('Bez pobočky');
        $req    = $this->fetchRequest($this->createRequest($this->userBorek, 'new'));
        $this->assertSame('none', requestAccessLevel($req, $nobody, false, userBranchIds($this->db, $nobody)));
    }

    // ─── Výpis ────────────────────────────────────────────────────────────────

    public function testListShowsOwnBranchAndOwnActiveRequestsForOtherBranch(): void
    {
        $borekReq      = $this->createRequest($this->userBorek, 'new', branchId: $this->borek);
        $taborReq      = $this->createRequest($this->userTabor, 'new', branchId: $this->tabor);
        $mineForTabor  = $this->createRequest($this->userBorek, 'in_progress', branchId: $this->tabor);
        $mineResolved  = $this->createRequest($this->userBorek, 'resolved', gmdate('Y-m-d H:i:s'), branchId: $this->tabor);

        $this->assertSame([$borekReq, $mineForTabor], $this->visibleIds($this->userBorek),
            'Borek vidí své požadavky + nevyřízený, který sám založil pro Tábor (ne vyřízený)');
        $this->assertSame([$taborReq, $mineForTabor, $mineResolved], $this->visibleIds($this->userTabor));
        $this->assertNotContains($mineResolved, $this->visibleIds($this->userBorek));
    }

    public function testUserWithoutBranchSeesOnlyOwnRequests(): void
    {
        $nobody = $this->createUser('Bez pobočky');
        $this->createRequest($this->userBorek, 'new');
        $own = $this->createRequest($nobody, 'new', branchId: $this->tabor);

        $this->assertSame([$own], $this->visibleIds($nobody));
    }

    public function testUserInTwoBranchesSeesBoth(): void
    {
        $boss = $this->createUser('Vedoucí');
        $this->assignUserToBranch($boss, $this->borek);
        $this->assignUserToBranch($boss, $this->tabor);
        $a = $this->createRequest($this->userBorek, 'new', branchId: $this->borek);
        $b = $this->createRequest($this->userTabor, 'new', branchId: $this->tabor);

        $this->assertSame([$a, $b], $this->visibleIds($boss));
    }

    public function testBranchAssignmentChangeAppliesImmediately(): void
    {
        $req = $this->createRequest($this->userTabor, 'new', branchId: $this->tabor);
        $this->assertSame([], $this->visibleIds($this->userBorek));

        $this->assignUserToBranch($this->userBorek, $this->tabor);

        $this->assertSame([$req], $this->visibleIds($this->userBorek), 'Bez nového přihlášení');
    }

    // ─── Přeřazení: validace ──────────────────────────────────────────────────

    /** @return array<string, mixed>|false */
    private function branchRow(int $id): array|false
    {
        $stmt = $this->db->prepare('SELECT * FROM tel_branches WHERE id = ?');
        $stmt->execute([$id]);
        return pdoFetch($stmt);
    }

    public function testFreshUnassignedRequestCanBeMovedWithoutReason(): void
    {
        $req = $this->fetchRequest($this->createRequest($this->userBorek, 'new'));
        $this->assertNull(validateBranchChange($req, $this->branchRow($this->tabor), ''));
    }

    public function testReasonRequiredForAssignedRequest(): void
    {
        $id = $this->createRequest($this->userBorek, 'in_progress');
        $this->db->prepare('UPDATE tel_requests SET assigned_to_id = ? WHERE id = ?')->execute([$this->userBorek, $id]);
        $req = $this->fetchRequest($id);

        $this->assertSame('Uveďte důvod přeřazení', validateBranchChange($req, $this->branchRow($this->tabor), ''));
        $this->assertNull(validateBranchChange($req, $this->branchRow($this->tabor), 'Klient bydlí v Táboře'));
    }

    public function testReasonRequiredForOlderRequest(): void
    {
        $old = gmdate('Y-m-d H:i:s', time() - (BRANCH_CHANGE_FREE_MINUTES + 1) * 60);
        $req = $this->fetchRequest($this->createRequest($this->userBorek, 'new', createdAt: $old));

        $this->assertSame('Uveďte důvod přeřazení', validateBranchChange($req, $this->branchRow($this->tabor), ''));
    }

    public function testResolvedRequestCannotBeMoved(): void
    {
        $req = $this->fetchRequest($this->createRequest($this->userBorek, 'resolved', gmdate('Y-m-d H:i:s')));
        $this->assertStringContainsString('znovu otevřete', (string) validateBranchChange($req, $this->branchRow($this->tabor), 'x'));
    }

    public function testReopenedAndPendingRequestsCanBeMoved(): void
    {
        foreach (['reopened', 'pending'] as $status) {
            $req = $this->fetchRequest($this->createRequest($this->userBorek, $status));
            $this->assertNull(validateBranchChange($req, $this->branchRow($this->tabor), 'důvod'), $status);
        }
    }

    public function testCannotMoveToSameOrInactiveOrMissingBranch(): void
    {
        $req      = $this->fetchRequest($this->createRequest($this->userBorek, 'new'));
        $inactive = $this->createBranch('OLD', 'Zrušená', null, false);

        $this->assertSame('Požadavek už na této pobočce je', validateBranchChange($req, $this->branchRow($this->borek), ''));
        $this->assertSame('Cílová pobočka neexistuje nebo není aktivní', validateBranchChange($req, $this->branchRow($inactive), ''));
        $this->assertSame('Cílová pobočka neexistuje nebo není aktivní', validateBranchChange($req, $this->branchRow(9999), ''));
    }

    // ─── Přeřazení: provedení ─────────────────────────────────────────────────

    public function testMovedRequestBecomesNewAndUnassignedOnTargetBranch(): void
    {
        $created = gmdate('Y-m-d H:i:s', time() - 3600);
        $id      = $this->createRequest($this->userBorek, 'pending', createdAt: $created);
        $this->db->prepare(
            "UPDATE tel_requests SET assigned_to_id = ?, assigned_at = ?, technician_note = 'Volal jsem klientovi' WHERE id = ?"
        )->execute([$this->userBorek, $created, $id]);

        applyBranchChange($this->db, $this->fetchRequest($id), $this->tabor, $this->userBorek, 'Vůz stojí v Táboře', gmdate('Y-m-d H:i:s'));

        $req = $this->fetchRequest($id);
        $this->assertSame($this->tabor, arrInt($req, 'branch_id'));
        $this->assertSame('new', $req['status']);
        $this->assertNull($req['assigned_to_id']);
        $this->assertNull($req['assigned_at']);
        $this->assertSame($created, $req['created_at'], 'Stáří požadavku se nemění');
        $this->assertSame('Volal jsem klientovi', $req['technician_note'], 'Poznámka technika zůstává');
    }

    public function testBranchChangeIsAudited(): void
    {
        $id = $this->createRequest($this->userBorek, 'in_progress');
        $this->db->prepare('UPDATE tel_requests SET assigned_to_id = ? WHERE id = ?')->execute([$this->userBorek, $id]);

        applyBranchChange($this->db, $this->fetchRequest($id), $this->tabor, $this->userBorek, 'Patří do Tábora', gmdate('Y-m-d H:i:s'));

        $stmt = $this->db->prepare(
            'SELECT action, field_name, old_value, new_value, user_id FROM tel_request_history WHERE request_id = ? ORDER BY id'
        );
        $stmt->execute([$id]);
        $rows = pdoFetchAll($stmt);

        $this->assertSame(
            [
                ['branch_changed', 'branch_id', (string) $this->borek, (string) $this->tabor],
                ['status_change', 'status', 'in_progress', 'new'],
                ['field_edit', 'assigned_to_id', (string) $this->userBorek, null],
                ['field_edit', 'branch_change_reason', null, 'Patří do Tábora'],
            ],
            array_map(static fn (array $r): array => [$r['action'], $r['field_name'], $r['old_value'], $r['new_value']], $rows)
        );
        foreach ($rows as $r) {
            $this->assertSame($this->userBorek, arrInt($r, 'user_id'));
        }
    }

    public function testAuthorKeepsAccessAfterMovingOwnRequest(): void
    {
        $id = $this->createRequest($this->userBorek, 'new');
        applyBranchChange($this->db, $this->fetchRequest($id), $this->tabor, $this->userBorek, '', gmdate('Y-m-d H:i:s'));

        $req = $this->fetchRequest($id);
        $this->assertSame('creator', requestAccessLevel($req, $this->userBorek, false, [$this->borek]));
        $this->assertSame('full', requestAccessLevel($req, $this->userTabor, false, [$this->tabor]));
        $this->assertContains($id, $this->visibleIds($this->userTabor));
    }

    // ─── Popisek střediska ────────────────────────────────────────────────────

    public function testCenterLabelUsesCommonPrefixOfSharedCenter(): void
    {
        $this->assertSame('Borek', centerLabel('3', ['Borek – servis', 'Borek – lakovna']));
        $this->assertSame('Tábor – servis', centerLabel('33', ['Tábor – servis']));
        $this->assertSame('středisko 7', centerLabel('7', []));
        $this->assertSame('středisko 9', centerLabel('9', ['Alfa', 'Beta']));
    }

    public function testCenterLabelsFromBranches(): void
    {
        $this->createBranch('LAK', 'Borek – lakovna', '3');
        $this->assertSame(['3' => 'Borek', '33' => 'Tábor – servis'], getCenterLabels($this->db));
    }
}
