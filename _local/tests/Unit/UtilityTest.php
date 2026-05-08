<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class UtilityTest extends TestCase
{
    // ─── normalizeSpz ─────────────────────────────────────────────────────────

    public function testNormalizeSpzStripsSpaces(): void
    {
        $this->assertSame('ABC123', normalizeSpz('ABC 123'));
    }

    public function testNormalizeSpzStripsDashes(): void
    {
        $this->assertSame('ABC123', normalizeSpz('ABC-123'));
    }

    public function testNormalizeSpzUppercases(): void
    {
        $this->assertSame('ABC123', normalizeSpz('abc123'));
    }

    public function testNormalizeSpzMixedWhitespaceAndDash(): void
    {
        $this->assertSame('ABC123', normalizeSpz(' abc - 123 '));
    }

    public function testNormalizeSpzEmptyString(): void
    {
        $this->assertSame('', normalizeSpz(''));
    }

    public function testNormalizeSpzAlreadyNormalized(): void
    {
        $this->assertSame('1AB2345', normalizeSpz('1AB2345'));
    }

    // ─── h() ──────────────────────────────────────────────────────────────────

    public function testHEscapesAngleBrackets(): void
    {
        $this->assertSame('&lt;script&gt;', h('<script>'));
    }

    public function testHEscapesDoubleQuotes(): void
    {
        $this->assertSame('&quot;test&quot;', h('"test"'));
    }

    public function testHEscapesSingleQuotes(): void
    {
        $this->assertSame('it&#039;s', h("it's"));
    }

    public function testHEscapesAmpersand(): void
    {
        $this->assertSame('a &amp; b', h('a & b'));
    }

    public function testHPreservesNormalText(): void
    {
        $this->assertSame('Ahoj světe', h('Ahoj světe'));
    }

    // ─── truncateForLog ───────────────────────────────────────────────────────

    public function testTruncateForLogPassesThroughShortString(): void
    {
        $this->assertSame('krátký', truncateForLog('krátký'));
    }

    public function testTruncateForLogPassesThroughExactLimit(): void
    {
        $s = str_repeat('x', 500);
        $this->assertSame($s, truncateForLog($s));
    }

    public function testTruncateForLogShortensLongString(): void
    {
        $s = str_repeat('a', 501);
        $result = truncateForLog($s);
        $this->assertLessThanOrEqual(500, mb_strlen($result));
        $this->assertStringEndsWith('[zkráceno]', $result);
    }

    public function testTruncateForLogRespectsCustomMax(): void
    {
        $s = str_repeat('b', 20);
        $result = truncateForLog($s, 10);
        $this->assertLessThanOrEqual(10, mb_strlen($result));
        $this->assertStringEndsWith('[zkráceno]', $result);
    }

    // ─── generateToken ────────────────────────────────────────────────────────

    public function testGenerateTokenDefaultLengthIs64Hex(): void
    {
        $token = generateToken();
        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $token);
    }

    public function testGenerateTokenCustomBytes(): void
    {
        $token = generateToken(16);
        $this->assertSame(32, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $token);
    }

    public function testGenerateTokenIsRandom(): void
    {
        $this->assertNotSame(generateToken(), generateToken());
    }

    // ─── nowUtc ───────────────────────────────────────────────────────────────

    public function testNowUtcReturnsValidDatetime(): void
    {
        $now = nowUtc();
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $now);
    }

    public function testNowUtcIsCloseToCurrentTime(): void
    {
        $now   = new DateTime(nowUtc(), new DateTimeZone('UTC'));
        $real  = new DateTime('now', new DateTimeZone('UTC'));
        $diffS = abs($real->getTimestamp() - $now->getTimestamp());
        $this->assertLessThan(5, $diffS);
    }
}
