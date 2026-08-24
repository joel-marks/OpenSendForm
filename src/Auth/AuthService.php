<?php

declare(strict_types=1);

namespace OpenSendForm\Auth;

use OpenSendForm\Clock\Clock;
use OpenSendForm\RateLimit\RateLimiter;

/**
 * Orchestrates admin authentication: password login, the TOTP second-factor
 * gate, session privilege state and idle/absolute timeouts.
 *
 * Security invariants enforced here:
 * - Login is rate-limited per-IP and per-email via the shared RateLimiter.
 * - An unknown email still runs a password_verify against a dummy hash, so a
 *   missing account is indistinguishable by timing from a wrong password.
 * - Both failure modes return the SAME outcome (Invalid) — no enumeration.
 * - The session id is regenerated on every privilege change (login / TOTP
 *   pass) and the whole session is destroyed on logout.
 * - A successful login opportunistically re-hashes the password when the
 *   stored hash's algorithm/parameters are out of date.
 */
final class AuthService
{
    /** Session keys owned by the auth stack. */
    public const SESSION_ADMIN_ID = 'auth.admin_id';
    public const SESSION_PENDING_TOTP = 'auth.pending_totp_admin_id';
    public const SESSION_LOGIN_AT = 'auth.login_at';
    public const SESSION_LAST_SEEN = 'auth.last_seen';

    /** Verified against for unknown emails, purely to equalise timing. */
    private const DUMMY_PASSWORD = 'osf-timing-equalisation-dummy';

    private AdminRepository $admins;
    private PasswordHasher $hasher;
    private Totp $totp;
    private SessionInterface $session;
    private RateLimiter $limiter;
    private Clock $clock;

    private int $maxPerIp;
    private int $maxPerEmail;
    private int $rateWindowSeconds;
    private int $idleTimeoutSeconds;
    private int $absoluteTimeoutSeconds;

    private ?string $dummyHash;

    public function __construct(
        AdminRepository $admins,
        PasswordHasher $hasher,
        Totp $totp,
        SessionInterface $session,
        RateLimiter $limiter,
        Clock $clock,
        int $maxPerIp = 10,
        int $maxPerEmail = 5,
        int $rateWindowSeconds = 900,
        int $idleTimeoutSeconds = 1800,
        int $absoluteTimeoutSeconds = 43200,
        ?string $dummyHash = null
    ) {
        $this->admins = $admins;
        $this->hasher = $hasher;
        $this->totp = $totp;
        $this->session = $session;
        $this->limiter = $limiter;
        $this->clock = $clock;
        $this->maxPerIp = $maxPerIp;
        $this->maxPerEmail = $maxPerEmail;
        $this->rateWindowSeconds = $rateWindowSeconds;
        $this->idleTimeoutSeconds = $idleTimeoutSeconds;
        $this->absoluteTimeoutSeconds = $absoluteTimeoutSeconds;
        $this->dummyHash = $dummyHash;
    }

    /**
     * Attempt a password login.
     *
     * On Success or NeedsTotp the session state is advanced accordingly; on
     * Invalid or RateLimited it is left untouched.
     */
    public function attemptLogin(string $email, string $password, string $ip): LoginOutcome
    {
        $email = strtolower(trim($email));

        // Rate limit before any expensive work. Both buckets are hit so a
        // spread-out attack on one email from many IPs and a burst from one
        // IP across many emails are each caught.
        $ipAllowed = $this->limiter->hit('adminlogin:ip:' . $ip, $this->maxPerIp, $this->rateWindowSeconds);
        $emailAllowed = $email === ''
            ? true
            : $this->limiter->hit('adminlogin:email:' . $email, $this->maxPerEmail, $this->rateWindowSeconds);
        if (!$ipAllowed || !$emailAllowed) {
            return LoginOutcome::RateLimited;
        }

        $admin = $this->admins->findByEmail($email);

        if ($admin === null) {
            // Equalise timing: still perform one verify against a valid hash.
            $this->hasher->verify($password, $this->dummyHash());

            return LoginOutcome::Invalid;
        }

        if (!$this->hasher->verify($password, $admin['password_hash'])) {
            return LoginOutcome::Invalid;
        }

        // Opportunistic rehash while we still hold the plaintext.
        if ($this->hasher->needsRehash($admin['password_hash'])) {
            $this->admins->updatePasswordHash($admin['id'], $this->hasher->hash($password));
        }

        if ((int) $admin['totp_enabled'] === 1) {
            // Password proven, but not yet authenticated: park the id for the
            // TOTP step and clear any prior full-login marker.
            $this->session->remove(self::SESSION_ADMIN_ID);
            $this->session->set(self::SESSION_PENDING_TOTP, $admin['id']);

            return LoginOutcome::NeedsTotp;
        }

        $this->completeLogin($admin['id']);

        return LoginOutcome::Success;
    }

    /**
     * The admin id awaiting TOTP entry, or null.
     */
    public function pendingTotpAdminId(): ?int
    {
        $id = $this->session->get(self::SESSION_PENDING_TOTP);

        return is_int($id) && $id > 0 ? $id : null;
    }

    /**
     * Verify the second factor for the pending admin: a current TOTP code or,
     * failing that, a single-use recovery code. On success the login is
     * completed (session regenerated, pending marker cleared).
     */
    public function verifyTotp(string $code): bool
    {
        $id = $this->pendingTotpAdminId();
        if ($id === null) {
            return false;
        }

        $admin = $this->admins->findById($id);
        if ($admin === null || (int) $admin['totp_enabled'] !== 1 || $admin['totp_secret'] === null) {
            return false;
        }

        if ($this->totp->verify($admin['totp_secret'], $code, $this->clock->now())) {
            $this->completeLogin($id);

            return true;
        }

        // Recovery-code fallback (single-use, consumed on match).
        if ($this->admins->consumeRecoveryCode($id, $code)) {
            $this->completeLogin($id);

            return true;
        }

        return false;
    }

    /**
     * The currently authenticated admin, or null. Enforces the idle and
     * absolute timeouts (destroying the session when either has elapsed) and
     * refreshes the idle marker on each live access.
     *
     * @return array<string, mixed>|null
     */
    public function currentAdmin(): ?array
    {
        $id = $this->session->get(self::SESSION_ADMIN_ID);
        if (!is_int($id) || $id <= 0) {
            return null;
        }

        $now = $this->clock->now();
        $loginAt = (int) $this->session->get(self::SESSION_LOGIN_AT, 0);
        $lastSeen = (int) $this->session->get(self::SESSION_LAST_SEEN, 0);

        if ($now - $loginAt > $this->absoluteTimeoutSeconds
            || $now - $lastSeen > $this->idleTimeoutSeconds
        ) {
            $this->logout();

            return null;
        }

        $admin = $this->admins->findById($id);
        if ($admin === null) {
            // Account removed underneath the session.
            $this->logout();

            return null;
        }

        $this->session->set(self::SESSION_LAST_SEEN, $now);

        return $admin;
    }

    /**
     * Destroy the session entirely (logout).
     */
    public function logout(): void
    {
        $this->session->destroy();
    }

    /**
     * Mark the session as fully authenticated for an admin: rotate the id,
     * clear the pending marker, set the login/idle anchors and stamp the DB.
     */
    private function completeLogin(int $adminId): void
    {
        $this->session->regenerate();
        $this->session->remove(self::SESSION_PENDING_TOTP);

        $now = $this->clock->now();
        $this->session->set(self::SESSION_ADMIN_ID, $adminId);
        $this->session->set(self::SESSION_LOGIN_AT, $now);
        $this->session->set(self::SESSION_LAST_SEEN, $now);

        $this->admins->recordLogin($adminId);
    }

    /**
     * Lazily-computed dummy hash for the unknown-email timing path.
     */
    private function dummyHash(): string
    {
        return $this->dummyHash ??= $this->hasher->hash(self::DUMMY_PASSWORD);
    }
}
