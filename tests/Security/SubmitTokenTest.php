<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Security;

use OpenSendForm\Security\SubmitToken;
use OpenSendForm\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;

final class SubmitTokenTest extends TestCase
{
    private const SECRET = 'unit-test-secret';
    private const MIN_AGE = 3;
    private const MAX_AGE = 3600;

    private function token(FixedClock $clock): SubmitToken
    {
        return new SubmitToken(self::SECRET, $clock, self::MIN_AGE, self::MAX_AGE);
    }

    public function testIssueHasTimestampDotHexSignatureFormat(): void
    {
        $clock = new FixedClock(1_700_000_000);
        $token = $this->token($clock)->issue('osf_abc');

        self::assertMatchesRegularExpression('/^\d+\.[0-9a-f]{64}$/', $token);
        self::assertStringStartsWith('1700000000.', $token);
    }

    public function testValidWhenAgeWithinWindow(): void
    {
        $clock = new FixedClock(1_700_000_000);
        $subject = $this->token($clock);
        $token = $subject->issue('osf_abc');

        // Advance past the minimum but within the maximum age.
        $clock->advance(10);

        self::assertSame(SubmitToken::VALID, $subject->verify($token, 'osf_abc'));
    }

    public function testTooYoungWhenSubmittedInstantly(): void
    {
        $clock = new FixedClock(1_700_000_000);
        $subject = $this->token($clock);
        $token = $subject->issue('osf_abc');

        // No time has passed — younger than MIN_AGE.
        self::assertSame(SubmitToken::TOO_YOUNG, $subject->verify($token, 'osf_abc'));

        $clock->advance(self::MIN_AGE - 1);
        self::assertSame(SubmitToken::TOO_YOUNG, $subject->verify($token, 'osf_abc'));
    }

    public function testExpiredWhenOlderThanMaxAge(): void
    {
        $clock = new FixedClock(1_700_000_000);
        $subject = $this->token($clock);
        $token = $subject->issue('osf_abc');

        $clock->advance(self::MAX_AGE + 1);

        self::assertSame(SubmitToken::EXPIRED, $subject->verify($token, 'osf_abc'));
    }

    public function testInvalidWhenSignatureForged(): void
    {
        $clock = new FixedClock(1_700_000_000);
        $subject = $this->token($clock);

        $forged = '1700000000.' . str_repeat('0', 64);
        $clock->advance(10);

        self::assertSame(SubmitToken::INVALID, $subject->verify($forged, 'osf_abc'));
    }

    public function testInvalidWhenFormKeyDiffersFromIssuance(): void
    {
        $clock = new FixedClock(1_700_000_000);
        $subject = $this->token($clock);
        $token = $subject->issue('osf_abc');
        $clock->advance(10);

        // A token minted for one form must not validate for another.
        self::assertSame(SubmitToken::INVALID, $subject->verify($token, 'osf_other'));
    }

    public function testInvalidWhenSecretDiffers(): void
    {
        $clock = new FixedClock(1_700_000_000);
        $issuer = $this->token($clock);
        $token = $issuer->issue('osf_abc');
        $clock->advance(10);

        $otherSecret = new SubmitToken('a-different-secret', $clock, self::MIN_AGE, self::MAX_AGE);
        self::assertSame(SubmitToken::INVALID, $otherSecret->verify($token, 'osf_abc'));
    }

    /**
     * @dataProvider malformedTokens
     */
    public function testInvalidWhenMalformed(string $token): void
    {
        $clock = new FixedClock(1_700_000_000);
        self::assertSame(SubmitToken::INVALID, $this->token($clock)->verify($token, 'osf_abc'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedTokens(): array
    {
        return [
            'empty'            => [''],
            'no dot'           => ['1700000000deadbeef'],
            'non-numeric ts'   => ['abc.' . str_repeat('0', 64)],
            'empty ts'         => ['.' . str_repeat('0', 64)],
        ];
    }

    public function testConstantTimeComparisonAcceptsGenuineSignature(): void
    {
        // A sanity check that a correctly-signed token round-trips; the
        // constant-time comparison must not reject a legitimate match.
        $clock = new FixedClock(1_700_000_000);
        $subject = $this->token($clock);
        $token = $subject->issue('osf_xyz');
        $clock->advance(self::MIN_AGE);

        self::assertSame(SubmitToken::VALID, $subject->verify($token, 'osf_xyz'));
    }
}
