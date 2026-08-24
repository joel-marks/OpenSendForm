<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Auth;

use OpenSendForm\Auth\Base32;
use OpenSendForm\Auth\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    /** The RFC 6238 SHA1 seed: the ASCII string "12345678901234567890". */
    private function rfcSecret(): string
    {
        return Base32::encode('12345678901234567890');
    }

    /**
     * RFC 6238 Appendix B test values for the SHA1 profile, truncated to the
     * 6 low-order digits (the RFC prints 8).
     *
     * @return array<string, array{int, string}>
     */
    public static function rfc6238Vectors(): array
    {
        return [
            '59'          => [59, '287082'],
            '1111111109'  => [1111111109, '081804'],
            '1111111111'  => [1111111111, '050471'],
            '1234567890'  => [1234567890, '005924'],
            '2000000000'  => [2000000000, '279037'],
            '20000000000' => [20000000000, '353130'],
        ];
    }

    /**
     * @dataProvider rfc6238Vectors
     */
    public function testCodeAtMatchesRfc6238(int $timestamp, string $expected): void
    {
        self::assertSame($expected, (new Totp())->codeAt($this->rfcSecret(), $timestamp));
    }

    public function testVerifyAcceptsCurrentCode(): void
    {
        $totp = new Totp();
        $secret = $this->rfcSecret();

        self::assertTrue($totp->verify($secret, $totp->codeAt($secret, 1234567890), 1234567890));
    }

    public function testVerifyAcceptsCodeWithinWindow(): void
    {
        $totp = new Totp();
        $secret = $this->rfcSecret();
        $now = 1234567890;

        // A code from the previous and next 30-second step is accepted at the
        // default +/-1 window.
        self::assertTrue($totp->verify($secret, $totp->codeAt($secret, $now - 30), $now));
        self::assertTrue($totp->verify($secret, $totp->codeAt($secret, $now + 30), $now));
    }

    public function testVerifyRejectsCodeOutsideWindow(): void
    {
        $totp = new Totp();
        $secret = $this->rfcSecret();
        $now = 1234567890;

        self::assertFalse($totp->verify($secret, $totp->codeAt($secret, $now - 60), $now));
        self::assertFalse($totp->verify($secret, $totp->codeAt($secret, $now + 60), $now));
    }

    public function testVerifyRejectsMalformedCode(): void
    {
        $totp = new Totp();
        $secret = $this->rfcSecret();

        self::assertFalse($totp->verify($secret, '12345', 59));   // too short
        self::assertFalse($totp->verify($secret, 'abcdef', 59));  // non-numeric
        self::assertFalse($totp->verify($secret, '', 59));
    }

    public function testGeneratedSecretIsUsableBase32(): void
    {
        $totp = new Totp();
        $secret = $totp->generateSecret();

        // Decodes to the requested 20 bytes and produces a verifiable code.
        self::assertSame(20, strlen(Base32::decode($secret)));
        self::assertTrue($totp->verify($secret, $totp->codeAt($secret, 1000), 1000));
    }

    public function testOtpauthUriIsWellFormed(): void
    {
        $uri = (new Totp())->otpauthUri('OpenSendForm', 'boss@example.com', 'JBSWY3DPEHPK3PXP');

        self::assertStringStartsWith('otpauth://totp/OpenSendForm:boss%40example.com?', $uri);
        self::assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        self::assertStringContainsString('issuer=OpenSendForm', $uri);
        self::assertStringContainsString('algorithm=SHA1', $uri);
        self::assertStringContainsString('digits=6', $uri);
        self::assertStringContainsString('period=30', $uri);
    }
}
