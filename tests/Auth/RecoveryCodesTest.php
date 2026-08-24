<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Auth;

use OpenSendForm\Auth\PasswordHasher;
use OpenSendForm\Auth\RecoveryCodes;
use PHPUnit\Framework\TestCase;

final class RecoveryCodesTest extends TestCase
{
    private function recovery(): RecoveryCodes
    {
        // bcrypt keeps the test fast while still exercising password_hash.
        return new RecoveryCodes(new PasswordHasher(PASSWORD_BCRYPT));
    }

    public function testGeneratesTenTenCharCodesWithMatchingHashes(): void
    {
        $batch = $this->recovery()->generate();

        self::assertCount(10, $batch['plain']);
        self::assertCount(10, $batch['hashes']);
        foreach ($batch['plain'] as $code) {
            self::assertMatchesRegularExpression('/^[A-Z0-9]{10}$/', $code);
        }
    }

    public function testConsumeAcceptsAValidCodeAndRemovesIt(): void
    {
        $recovery = $this->recovery();
        $batch = $recovery->generate();
        $hashes = $batch['hashes'];
        $code = $batch['plain'][3];

        $remaining = $recovery->consume($code, $hashes);

        self::assertNotNull($remaining);
        self::assertCount(9, $remaining);
    }

    public function testCodeIsSingleUse(): void
    {
        $recovery = $this->recovery();
        $batch = $recovery->generate();
        $code = $batch['plain'][0];

        $remaining = $recovery->consume($code, $batch['hashes']);
        self::assertNotNull($remaining);

        // The same code no longer matches the reduced set.
        self::assertNull($recovery->consume($code, $remaining));
    }

    public function testConsumeRejectsUnknownCode(): void
    {
        $recovery = $this->recovery();
        $batch = $recovery->generate();

        self::assertNull($recovery->consume('ZZZZZZZZZZ', $batch['hashes']));
    }

    public function testConsumeIsInputTolerant(): void
    {
        $recovery = $this->recovery();
        $batch = $recovery->generate();
        $code = $batch['plain'][2];

        // Lowercased, dashed and spaced input still matches.
        $messy = strtolower(substr($code, 0, 5) . '-' . substr($code, 5));

        self::assertNotNull($recovery->consume(" {$messy} ", $batch['hashes']));
    }

    public function testEncodeDecodeRoundTrip(): void
    {
        $recovery = $this->recovery();
        $hashes = $recovery->generate()['hashes'];

        self::assertSame($hashes, $recovery->decode($recovery->encode($hashes)));
        self::assertSame([], $recovery->decode(null));
        self::assertSame([], $recovery->decode(''));
    }
}
