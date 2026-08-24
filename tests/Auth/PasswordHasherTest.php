<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Auth;

use OpenSendForm\Auth\PasswordHasher;
use PHPUnit\Framework\TestCase;

final class PasswordHasherTest extends TestCase
{
    public function testHashAndVerifyRoundTrip(): void
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash('correct horse battery staple');

        self::assertTrue($hasher->verify('correct horse battery staple', $hash));
        self::assertFalse($hasher->verify('wrong password', $hash));
    }

    public function testUsesArgon2idWhenAvailable(): void
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            self::markTestSkipped('This PHP build has no argon2id support.');
        }

        $hash = (new PasswordHasher())->hash('secret-password');

        self::assertStringStartsWith('$argon2id$', $hash);
    }

    public function testForcedBcryptFallbackPath(): void
    {
        $hasher = new PasswordHasher(PASSWORD_BCRYPT);
        $hash = $hasher->hash('secret-password');

        self::assertStringStartsWith('$2y$', $hash);
        self::assertTrue($hasher->verify('secret-password', $hash));
        // A bcrypt hash does not need rehashing under a bcrypt hasher.
        self::assertFalse($hasher->needsRehash($hash));
    }

    public function testNeedsRehashWhenAlgorithmUpgrades(): void
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            self::markTestSkipped('This PHP build has no argon2id support.');
        }

        $bcryptHash = (new PasswordHasher(PASSWORD_BCRYPT))->hash('secret-password');

        // An argon2id hasher considers a legacy bcrypt hash in need of rehash.
        self::assertTrue((new PasswordHasher(PASSWORD_ARGON2ID))->needsRehash($bcryptHash));
    }

    public function testNeedsRehashFalseForCurrentAlgorithm(): void
    {
        $hasher = new PasswordHasher();

        self::assertFalse($hasher->needsRehash($hasher->hash('secret-password')));
    }
}
