<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Auth;

use InvalidArgumentException;
use OpenSendForm\Auth\AdminRepository;
use OpenSendForm\Auth\PasswordHasher;
use OpenSendForm\Auth\RecoveryCodes;
use OpenSendForm\Storage\Database;
use OpenSendForm\Storage\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class AdminRepositoryTest extends TestCase
{
    private Database $db;
    private PasswordHasher $hasher;
    private AdminRepository $repo;

    protected function setUp(): void
    {
        $this->db = Database::connect('sqlite::memory:');
        (new MigrationRunner($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->hasher = new PasswordHasher(PASSWORD_BCRYPT);
        $this->repo = new AdminRepository($this->db, $this->hasher, new RecoveryCodes($this->hasher));
    }

    public function testCreateAndFindByEmail(): void
    {
        $admin = $this->repo->createAdmin('Boss@Example.com', 'The Boss', 'a-strong-password');

        self::assertSame('boss@example.com', $admin['email']); // normalised
        self::assertSame('The Boss', $admin['display_name']);
        self::assertSame(0, $admin['totp_enabled']);
        self::assertNull($admin['last_login_at']);
        self::assertTrue($this->hasher->verify('a-strong-password', $admin['password_hash']));

        // Lookup is case-insensitive.
        self::assertSame($admin['id'], $this->repo->findByEmail('BOSS@example.com')['id']);
    }

    public function testCreateRejectsBadEmail(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->createAdmin('not-an-email', 'Name', 'a-strong-password');
    }

    public function testRecordLoginStampsTimestamp(): void
    {
        $admin = $this->repo->createAdmin('boss@example.com', 'Boss', 'a-strong-password');
        self::assertNull($admin['last_login_at']);

        $this->repo->recordLogin($admin['id']);

        self::assertNotNull($this->repo->findById($admin['id'])['last_login_at']);
    }

    public function testUpdatePasswordHash(): void
    {
        $admin = $this->repo->createAdmin('boss@example.com', 'Boss', 'a-strong-password');
        $newHash = $this->hasher->hash('a-different-password');

        $this->repo->updatePasswordHash($admin['id'], $newHash);

        self::assertSame($newHash, $this->repo->findById($admin['id'])['password_hash']);
    }

    public function testTotpEnableAndDisableLifecycle(): void
    {
        $admin = $this->repo->createAdmin('boss@example.com', 'Boss', 'a-strong-password');

        $this->repo->setTotp($admin['id'], 'JBSWY3DPEHPK3PXP');
        $this->repo->enableTotp($admin['id']);
        $this->repo->setRecoveryCodes($admin['id'], [$this->hasher->hash('CODE0')]);

        $loaded = $this->repo->findById($admin['id']);
        self::assertSame(1, $loaded['totp_enabled']);
        self::assertSame('JBSWY3DPEHPK3PXP', $loaded['totp_secret']);
        self::assertNotNull($loaded['recovery_codes']);

        $this->repo->disableTotp($admin['id']);

        $loaded = $this->repo->findById($admin['id']);
        self::assertSame(0, $loaded['totp_enabled']);
        self::assertNull($loaded['totp_secret']);
        self::assertNull($loaded['recovery_codes']);
    }

    public function testConsumeRecoveryCode(): void
    {
        $admin = $this->repo->createAdmin('boss@example.com', 'Boss', 'a-strong-password');
        $recovery = new RecoveryCodes($this->hasher);
        $batch = $recovery->generate();
        $this->repo->setRecoveryCodes($admin['id'], $batch['hashes']);

        self::assertTrue($this->repo->consumeRecoveryCode($admin['id'], $batch['plain'][0]));
        // Single-use: the same code no longer works.
        self::assertFalse($this->repo->consumeRecoveryCode($admin['id'], $batch['plain'][0]));
        // A still-unused code works.
        self::assertTrue($this->repo->consumeRecoveryCode($admin['id'], $batch['plain'][1]));
    }
}
