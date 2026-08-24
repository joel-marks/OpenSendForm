<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Auth;

use OpenSendForm\Auth\AdminRepository;
use OpenSendForm\Auth\AuthService;
use OpenSendForm\Auth\LoginOutcome;
use OpenSendForm\Auth\PasswordHasher;
use OpenSendForm\Auth\RecoveryCodes;
use OpenSendForm\Auth\Totp;
use OpenSendForm\RateLimit\RateLimiter;
use OpenSendForm\Storage\Database;
use OpenSendForm\Storage\MigrationRunner;
use OpenSendForm\Tests\Support\CountingPasswordHasher;
use OpenSendForm\Tests\Support\FakeSession;
use OpenSendForm\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;

/**
 * The full AuthService outcome matrix, driven against an in-memory database
 * with an array-backed session and a fixed clock. No wall-clock or globals.
 */
final class AuthServiceTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery-staple';
    private const IP = '203.0.113.5';
    private const T0 = 1_700_000_000;

    private Database $db;
    private FixedClock $clock;
    private FakeSession $session;
    private CountingPasswordHasher $hasher;
    private AdminRepository $admins;
    private Totp $totp;
    private RateLimiter $limiter;

    protected function setUp(): void
    {
        $this->db = Database::connect('sqlite::memory:');
        (new MigrationRunner($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();

        $this->clock = new FixedClock(self::T0);
        $this->session = new FakeSession();
        $this->hasher = new CountingPasswordHasher();
        $this->admins = new AdminRepository($this->db, $this->hasher, new RecoveryCodes($this->hasher));
        $this->totp = new Totp();
        $this->limiter = new RateLimiter($this->db, $this->clock);
    }

    private function service(int $maxPerIp = 10, int $maxPerEmail = 5): AuthService
    {
        return new AuthService(
            $this->admins,
            $this->hasher,
            $this->totp,
            $this->session,
            $this->limiter,
            $this->clock,
            $maxPerIp,
            $maxPerEmail
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function makeAdmin(bool $withTotp = false): array
    {
        $admin = $this->admins->createAdmin('boss@example.com', 'The Boss', self::PASSWORD);

        if ($withTotp) {
            $this->admins->setTotp($admin['id'], $this->totp->generateSecret());
            $this->admins->enableTotp($admin['id']);
            $admin = $this->admins->findById($admin['id']);
        }

        return $admin;
    }

    // --- Outcome matrix ---------------------------------------------------

    public function testSuccessWhenTotpDisabled(): void
    {
        $admin = $this->makeAdmin();

        $outcome = $this->service()->attemptLogin('boss@example.com', self::PASSWORD, self::IP);

        self::assertSame(LoginOutcome::Success, $outcome);
        self::assertSame($admin['id'], $this->session->get(AuthService::SESSION_ADMIN_ID));
        // last_login_at was stamped.
        self::assertNotNull($this->admins->findById($admin['id'])['last_login_at']);
    }

    public function testSessionRegeneratedOnLogin(): void
    {
        $this->makeAdmin();

        self::assertSame(0, $this->session->regenerateCount());
        $this->service()->attemptLogin('boss@example.com', self::PASSWORD, self::IP);
        self::assertSame(1, $this->session->regenerateCount());
    }

    public function testWrongPasswordIsInvalid(): void
    {
        $this->makeAdmin();

        $outcome = $this->service()->attemptLogin('boss@example.com', 'nope', self::IP);

        self::assertSame(LoginOutcome::Invalid, $outcome);
        self::assertFalse($this->session->has(AuthService::SESSION_ADMIN_ID));
    }

    public function testUnknownEmailIsInvalidAndStillHashes(): void
    {
        // No admin created. The dummy-hash timing path must run a verify().
        $before = $this->hasher->verifyCalls();

        $outcome = $this->service()->attemptLogin('nobody@example.com', 'whatever', self::IP);

        self::assertSame(LoginOutcome::Invalid, $outcome);
        self::assertGreaterThan($before, $this->hasher->verifyCalls());
        self::assertFalse($this->session->has(AuthService::SESSION_ADMIN_ID));
    }

    public function testNeedsTotpWhenEnabled(): void
    {
        $admin = $this->makeAdmin(true);

        $outcome = $this->service()->attemptLogin('boss@example.com', self::PASSWORD, self::IP);

        self::assertSame(LoginOutcome::NeedsTotp, $outcome);
        self::assertFalse($this->session->has(AuthService::SESSION_ADMIN_ID));
        self::assertSame($admin['id'], $this->service()->pendingTotpAdminId());
    }

    public function testRateLimitedAfterTooManyFailures(): void
    {
        $this->makeAdmin();
        $service = $this->service(3); // 3 attempts per IP window

        for ($i = 0; $i < 3; $i++) {
            self::assertSame(
                LoginOutcome::Invalid,
                $service->attemptLogin('boss@example.com', 'wrong', self::IP)
            );
        }

        self::assertSame(
            LoginOutcome::RateLimited,
            $service->attemptLogin('boss@example.com', self::PASSWORD, self::IP)
        );
    }

    // --- TOTP gate --------------------------------------------------------

    public function testVerifyTotpCompletesLoginWithValidCode(): void
    {
        $admin = $this->makeAdmin(true);
        $service = $this->service();
        $service->attemptLogin('boss@example.com', self::PASSWORD, self::IP);

        $secret = $this->admins->findById($admin['id'])['totp_secret'];
        $code = $this->totp->codeAt($secret, self::T0);

        self::assertTrue($service->verifyTotp($code));
        self::assertSame($admin['id'], $this->session->get(AuthService::SESSION_ADMIN_ID));
        self::assertNull($this->session->get(AuthService::SESSION_PENDING_TOTP));
    }

    public function testVerifyTotpRejectsWrongCode(): void
    {
        $this->makeAdmin(true);
        $service = $this->service();
        $service->attemptLogin('boss@example.com', self::PASSWORD, self::IP);

        self::assertFalse($service->verifyTotp('000000'));
        self::assertFalse($this->session->has(AuthService::SESSION_ADMIN_ID));
    }

    public function testVerifyTotpAcceptsRecoveryCodeOnce(): void
    {
        $admin = $this->makeAdmin(true);
        $recovery = new RecoveryCodes($this->hasher);
        $batch = $recovery->generate();
        $this->admins->setRecoveryCodes($admin['id'], $batch['hashes']);

        $service = $this->service();
        $service->attemptLogin('boss@example.com', self::PASSWORD, self::IP);
        self::assertTrue($service->verifyTotp($batch['plain'][0]));

        // The same recovery code cannot be reused on a subsequent login.
        $service->logout();
        $service->attemptLogin('boss@example.com', self::PASSWORD, self::IP);
        self::assertFalse($service->verifyTotp($batch['plain'][0]));
    }

    public function testVerifyTotpFailsWithoutPendingStep(): void
    {
        $this->makeAdmin(true);

        self::assertFalse($this->service()->verifyTotp('123456'));
    }

    // --- Session lifetime -------------------------------------------------

    public function testCurrentAdminReturnsLoggedInAdmin(): void
    {
        $admin = $this->makeAdmin();
        $service = $this->service();
        $service->attemptLogin('boss@example.com', self::PASSWORD, self::IP);

        self::assertSame($admin['id'], $service->currentAdmin()['id']);
    }

    public function testIdleTimeoutLogsOut(): void
    {
        $this->makeAdmin();
        $service = $this->service();
        $service->attemptLogin('boss@example.com', self::PASSWORD, self::IP);

        // 30 minutes + 1 second of inactivity.
        $this->clock->advance(1800 + 1);

        self::assertNull($service->currentAdmin());
        self::assertSame(1, $this->session->destroyCount());
    }

    public function testAbsoluteTimeoutLogsOut(): void
    {
        $this->makeAdmin();
        $service = $this->service();
        $service->attemptLogin('boss@example.com', self::PASSWORD, self::IP);

        // Touch the session every 15 minutes so the idle timeout (30 min)
        // never trips; only the 12-hour absolute cap should end the session.
        // 48 steps of 900s reach exactly T0+43200 (still valid).
        for ($step = 1; $step <= 48; $step++) {
            $this->clock->advance(900);
            self::assertNotNull($service->currentAdmin(), "step {$step}");
        }

        // One more step crosses the 12-hour cap.
        $this->clock->advance(900);
        self::assertNull($service->currentAdmin());
    }

    public function testLogoutDestroysSession(): void
    {
        $this->makeAdmin();
        $service = $this->service();
        $service->attemptLogin('boss@example.com', self::PASSWORD, self::IP);

        $service->logout();

        self::assertSame(1, $this->session->destroyCount());
        self::assertNull($service->currentAdmin());
    }
}
