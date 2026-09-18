<?php
declare(strict_types=1);

/**
 * Import objednávek do servisu z planovac-objednano.csv (tel_service_orders).
 * Klíčové: VIN = fabkod + vinkod, převod času Praha → UTC, nahrazení celé tabulky.
 */
final class ServiceOrdersImportTest extends DatabaseTestCase
{
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function writeCsv(string $content, bool $win1250 = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ord_test_');
        if ($path === false) {
            $this->fail('Nelze vytvořit dočasný soubor');
        }
        if ($win1250) {
            $converted = iconv('UTF-8', 'windows-1250', $content);
            $this->assertNotFalse($converted);
            $content = $converted;
        }
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;
        return $path;
    }

    private const HEADER = "\"datum_zac\";\"spz\";\"fabkod\";\"vinkod\";\"klient\"\r\n";

    /** @return list<array<string,mixed>> */
    private function allOrders(): array
    {
        $stmt = $this->db->query('SELECT * FROM tel_service_orders ORDER BY id');
        $this->assertNotFalse($stmt);
        return pdoFetchAll($stmt);
    }

    public function testVinIsComposedFromFabkodAndVinkod(): void
    {
        $csv = self::HEADER
             . "\"2026-09-18 09:30:00\";\"1CE 05-23 \";\"VF1\";\"RFK00574387664\";\"GW JIHOTRANS a.s.   \"\r\n";
        $result = importServiceOrdersCsv($this->db, $this->writeCsv($csv));

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);

        $rows = $this->allOrders();
        $this->assertCount(1, $rows);
        $this->assertSame('VF1RFK00574387664', $rows[0]['vin']);
        $this->assertSame('1CE0523', $rows[0]['spz_normalized']);
        $this->assertSame('1CE 05-23', $rows[0]['spz_original']);
        $this->assertSame('GW JIHOTRANS a.s.', $rows[0]['client_name']);
    }

    public function testScheduledAtIsConvertedFromPragueToUtc(): void
    {
        // Letní čas (CEST = UTC+2) a zimní čas (CET = UTC+1)
        $csv = self::HEADER
             . "\"2026-09-18 09:30:00\";\"1CE 05-23\";\"VF1\";\"RFK00574387664\";\"A\"\r\n"
             . "\"2026-12-14 08:30:00\";\"9C9 53-73\";\"VF1\";\"RJL002UC376143\";\"B\"\r\n";
        $result = importServiceOrdersCsv($this->db, $this->writeCsv($csv));

        $this->assertSame([], $result['errors']);
        $rows = $this->allOrders();
        $this->assertSame('2026-09-18 07:30:00', $rows[0]['scheduled_at']);
        $this->assertSame('2026-12-14 07:30:00', $rows[1]['scheduled_at']);
        $this->assertSame('18.09.2026 09:30', toLocalTime((string) $rows[0]['scheduled_at']));
    }

    public function testImportReplacesWholeTable(): void
    {
        $first = self::HEADER
               . "\"2026-09-18 09:30:00\";\"1CE 05-23\";\"VF1\";\"RFK00574387664\";\"A\"\r\n"
               . "\"2026-09-19 10:00:00\";\"9C9 65-39\";\"VF1\";\"HJD40871440732\";\"B\"\r\n";
        importServiceOrdersCsv($this->db, $this->writeCsv($first));
        $this->assertCount(2, $this->allOrders());

        // Druhý snímek už první objednávku neobsahuje (zrušena) — musí z DB zmizet
        $second = self::HEADER
                . "\"2026-09-19 10:00:00\";\"9C9 65-39\";\"VF1\";\"HJD40871440732\";\"B\"\r\n"
                . "\"2026-09-21 07:00:00\";\"1CC 57-15\";\"UU1\";\"DJF00573054256\";\"C\"\r\n";
        $result = importServiceOrdersCsv($this->db, $this->writeCsv($second));

        $this->assertSame(2, $result['imported']);
        $spz = array_map(static fn (array $r): mixed => $r['spz_normalized'], $this->allOrders());
        $this->assertSame(['9C96539', '1CC5715'], $spz);
    }

    public function testWindows1250EncodingIsConverted(): void
    {
        $csv = self::HEADER
             . "\"2026-09-18 10:00:00\";\"1AL B0-41\";\"UU1\";\"DJF01875411457\";\"Jindřichová Zlatuše Ing.\"\r\n";
        $result = importServiceOrdersCsv($this->db, $this->writeCsv($csv, true));

        $this->assertSame([], $result['errors']);
        $rows = $this->allOrders();
        $this->assertSame('Jindřichová Zlatuše Ing.', $rows[0]['client_name']);
    }

    public function testRowsWithoutSpzButWithVinAreKept(): void
    {
        $csv = self::HEADER
             . "\"2026-09-18 10:00:00\";\"\";\"VF1\";\"RFK00574387664\";\"Nové vozidlo\"\r\n";
        $result = importServiceOrdersCsv($this->db, $this->writeCsv($csv));

        $this->assertSame(1, $result['imported']);
        $rows = $this->allOrders();
        $this->assertNull($rows[0]['spz_normalized']);
        $this->assertSame('VF1RFK00574387664', $rows[0]['vin']);
    }

    public function testInvalidRowsAreSkipped(): void
    {
        $csv = self::HEADER
             . "\"neni datum\";\"1CE 05-23\";\"VF1\";\"RFK00574387664\";\"A\"\r\n"   // neplatné datum
             . "\"2026-09-18 10:00:00\";\"\";\"VF\";\"KRATKY\";\"B\"\r\n"              // bez SPZ, VIN neplatný
             . "\"2026-09-18 10:00:00\";\"1CE 05-23\"\r\n"                             // málo sloupců
             . "\"2026-09-18 11:00:00\";\"6C4 12-83\";\"vf1\";\"bz1r0748881334\";\"C\"\r\n"; // OK, VIN se převede na velká
        $result = importServiceOrdersCsv($this->db, $this->writeCsv($csv));

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);
        $this->assertSame(3, $result['skipped']);
        $this->assertSame('VF1BZ1R0748881334', $this->allOrders()[0]['vin']);
    }

    public function testInvalidVinIsStoredAsNullWhenSpzPresent(): void
    {
        $csv = self::HEADER
             . "\"2026-09-18 10:00:00\";\"1CE 05-23\";\"VF1\";\"RFK0057438766\";\"A\"\r\n"; // 16 znaků
        importServiceOrdersCsv($this->db, $this->writeCsv($csv));

        $rows = $this->allOrders();
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['vin']);
    }

    public function testWrongFileFormatDoesNotTouchDatabase(): void
    {
        $good = self::HEADER
              . "\"2026-09-18 09:30:00\";\"1CE 05-23\";\"VF1\";\"RFK00574387664\";\"A\"\r\n";
        importServiceOrdersCsv($this->db, $this->writeCsv($good));

        // Např. omylem soubor vozidel (export-spz.csv)
        $wrong  = "\"klic\";\"spz\";\"nazvoz\";\"rokvyr\";\"vin\"\r\n\"1\";\"CBK 30-72\";\"Audi 80\";\"\";\"X\"\r\n";
        $result = importServiceOrdersCsv($this->db, $this->writeCsv($wrong));

        $this->assertNotSame([], $result['errors']);
        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $this->allOrders(), 'Původní data musí zůstat zachována');
    }

    public function testRealSampleFileImportsAllRows(): void
    {
        $sample = 'C:/Users/stach/Downloads/planovac-objednano.csv';
        if (!is_file($sample)) {
            $this->markTestSkipped('Vzorový soubor není k dispozici');
        }
        $result = importServiceOrdersCsv($this->db, $sample);

        $this->assertSame([], $result['errors']);
        $this->assertSame(289, $result['imported']);
        $this->assertSame(0, $result['skipped']);

        $stmt = $this->db->query('SELECT COUNT(*) FROM tel_service_orders WHERE vin IS NULL');
        $this->assertNotFalse($stmt);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'Všechny řádky vzorku mají platný 17znakový VIN');
    }

    public function testUpcomingOrdersIncludeTodayAndFutureOnly(): void
    {
        $prague    = new DateTimeZone('Europe/Prague');
        $today     = (new DateTime('today', $prague))->format('Y-m-d') . ' 00:30:00';   // dnes ráno (už minulo)
        $yesterday = (new DateTime('yesterday', $prague))->format('Y-m-d') . ' 15:00:00';
        $future    = (new DateTime('+3 days', $prague))->format('Y-m-d') . ' 09:00:00';

        $csv = self::HEADER
             . "\"{$yesterday}\";\"1CE 05-23\";\"VF1\";\"RFK00574387664\";\"Včera\"\r\n"
             . "\"{$today}\";\"1CE 05-23\";\"VF1\";\"RFK00574387664\";\"Dnes\"\r\n"
             . "\"{$future}\";\"1CE 05-23\";\"VF1\";\"RFK00574387664\";\"Budoucnost\"\r\n";
        importServiceOrdersCsv($this->db, $this->writeCsv($csv));

        $orders  = getUpcomingServiceOrders($this->db, ['1CE0523'], []);
        $clients = array_map(static fn (array $o): string => $o['client_name'], $orders);
        $this->assertSame(['Dnes', 'Budoucnost'], $clients, 'Dnešní objednávka zůstává i po svém čase, včerejší ne');
    }

    public function testOrdersAreMatchedBySpzOrVin(): void
    {
        $future = (new DateTime('+1 day', new DateTimeZone('Europe/Prague')))->format('Y-m-d') . ' 08:00:00';
        $csv = self::HEADER
             . "\"{$future}\";\"1CE 05-23\";\"VF1\";\"RFK00574387664\";\"Podle SPZ\"\r\n"
             . "\"{$future}\";\"\";\"UU1\";\"DJF01875411457\";\"Podle VIN\"\r\n"
             . "\"{$future}\";\"9C9 65-39\";\"VF1\";\"HJD40871440732\";\"Cizí\"\r\n";
        importServiceOrdersCsv($this->db, $this->writeCsv($csv));

        $all = getUpcomingServiceOrders($this->db, ['1CE0523', 'XYZ'], ['UU1DJF01875411457']);
        $this->assertCount(2, $all);

        $bySpz = matchServiceOrders($all, '1CE0523', '');
        $this->assertCount(1, $bySpz);
        $this->assertSame('Podle SPZ', $bySpz[0]['client_name']);

        $byVin = matchServiceOrders($all, 'XYZ', 'UU1DJF01875411457');
        $this->assertCount(1, $byVin);
        $this->assertSame('Podle VIN', $byVin[0]['client_name']);

        $this->assertSame([], matchServiceOrders($all, 'XYZ', ''));
        $this->assertSame([], getUpcomingServiceOrders($this->db, [], []));
    }

    public function testResolvedRequestShowsOnlyOrdersOnOrAfterResolutionDay(): void
    {
        $prague = new DateTimeZone('Europe/Prague');
        $today  = (new DateTime('today', $prague))->format('Y-m-d');
        $tomorrow = (new DateTime('tomorrow', $prague))->format('Y-m-d');

        $csv = self::HEADER
             . "\"{$today} 00:30:00\";\"1CE 05-23\";\"VF1\";\"RFK00574387664\";\"Dnes\"
"
             . "\"{$tomorrow} 09:00:00\";\"1CE 05-23\";\"VF1\";\"RFK00574387664\";\"Zítra\"
";
        importServiceOrdersCsv($this->db, $this->writeCsv($csv));
        $all = getUpcomingServiceOrders($this->db, ['1CE0523'], []);
        $this->assertCount(2, $all);

        $names = static fn (array $list): array => array_map(static fn (array $o): string => $o['client_name'], $list);

        // Nevyřízený požadavek: vše
        $this->assertSame(['Dnes', 'Zítra'], $names(matchServiceOrders($all, '1CE0523', '')));

        // Vyřízeno dnes (v kteroukoli hodinu): dnešní objednávka zůstává (stejný den)
        $resolvedToday = pragueDayStartUtc(nowUtc());
        $this->assertSame(['Dnes', 'Zítra'], $names(matchServiceOrders($all, '1CE0523', '', $resolvedToday)));

        // Vyřízeno zítra (teoreticky): dnešní objednávka už ne
        $resolvedTomorrow = pragueDayStartUtc(gmdate('Y-m-d H:i:s', strtotime('+1 day')));
        $this->assertSame(['Zítra'], $names(matchServiceOrders($all, '1CE0523', '', $resolvedTomorrow)));
    }
    public function testEachSourceReplacesOnlyItsOwnRows(): void
    {
        $objednano = self::HEADER
            . "\"2026-09-21 07:00:00\";\"1CC 57-15\";\"UU1\";\"DJF00573054256\";\"A\"\r\n"
            . "\"2026-09-22 08:00:00\";\"1CE 05-23\";\"VF1\";\"RFK00574387664\";\"B\"\r\n";
        $prijem = self::HEADER
            . "\"2026-09-21 07:00:00\";\"1CC 57-15\";\"UU1\";\"DJF00573054256\";\"A\"\r\n"
            . "\"2026-09-23 09:00:00\";\"9C9 65-39\";\"VF1\";\"HJD40871440732\";\"C\"\r\n";

        importServiceOrdersCsv($this->db, $this->writeCsv($objednano), 'objednano');
        importServiceOrdersCsv($this->db, $this->writeCsv($prijem), 'prijem');
        $this->assertCount(4, $this->allOrders());

        // Nový snímek "objednano" nesmí sáhnout na řádky "prijem"
        $objednano2 = self::HEADER
            . "\"2026-09-22 08:00:00\";\"1CE 05-23\";\"VF1\";\"RFK00574387664\";\"B\"\r\n";
        importServiceOrdersCsv($this->db, $this->writeCsv($objednano2), 'objednano');

        $rows = $this->allOrders();
        $this->assertCount(3, $rows);
        $sources = array_count_values(array_map(static fn (array $r): string => (string) $r['source'], $rows));
        $this->assertSame(['prijem' => 2, 'objednano' => 1], $sources);

        $this->assertNotSame([], importServiceOrdersCsv($this->db, $this->writeCsv($objednano), 'neznamy')['errors']);
    }

    public function testSameAppointmentInBothSourcesIsShownOnce(): void
    {
        $future  = (new DateTime('+2 days', new DateTimeZone('Europe/Prague')))->format('Y-m-d');
        $shared  = "\"{$future} 07:00:00\";\"1CC 57-15\";\"UU1\";\"DJF00573054256\";\"Sdílený\"\r\n";
        $onlyPr  = "\"{$future} 13:00:00\";\"1CC 57-15\";\"UU1\";\"DJF00573054256\";\"Jen příjem\"\r\n";

        importServiceOrdersCsv($this->db, $this->writeCsv(self::HEADER . $shared), 'objednano');
        importServiceOrdersCsv($this->db, $this->writeCsv(self::HEADER . $shared . $onlyPr), 'prijem');

        $all     = getUpcomingServiceOrders($this->db, ['1CC5715'], []);
        $matched = matchServiceOrders($all, '1CC5715', '');

        $this->assertCount(3, $all);
        $this->assertCount(2, $matched);
        $this->assertSame(['objednano', 'prijem'], array_column($matched, 'source'));
        $this->assertSame(['Sdílený', 'Jen příjem'], array_column($matched, 'client_name'));
    }

    public function testRealPrijemFileImportsAllRows(): void
    {
        $sample = 'C:/Users/stach/Downloads/planovac-prijem.csv';
        if (!is_file($sample)) {
            $this->markTestSkipped('Vzorový soubor není k dispozici');
        }
        $result = importServiceOrdersCsv($this->db, $sample, 'prijem');

        $this->assertSame([], $result['errors']);
        $this->assertSame(293, $result['imported']);
        $this->assertSame(0, $result['skipped']);
    }
}
