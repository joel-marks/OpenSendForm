<?php

declare(strict_types=1);

namespace OpenSendForm\Auth;

/**
 * Password hashing with opportunistic-rehash support.
 *
 * The algorithm is chosen once at construction: argon2id when this PHP
 * build supports it, otherwise PASSWORD_DEFAULT (bcrypt on stock shared
 * hosting). The algorithm is injectable so tests can force the fallback
 * path and exercise needsRehash() without depending on the host's libargon2.
 *
 * Not final: tests spy on verify() (e.g. to assert the unknown-email
 * timing path still hashes) by extending this class.
 */
class PasswordHasher
{
    /** @var string|int PHP password_* algorithm identifier. */
    private string|int $algorithm;

    /**
     * @param string|int|null $algorithm A PASSWORD_* constant. Null selects
     *        argon2id when available, else PASSWORD_DEFAULT.
     */
    public function __construct(string|int|null $algorithm = null)
    {
        $this->algorithm = $algorithm ?? self::preferredAlgorithm();
    }

    /**
     * The strongest algorithm this build supports: argon2id when the
     * extension is present, otherwise PASSWORD_DEFAULT.
     */
    public static function preferredAlgorithm(): string|int
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    public function hash(string $password): string
    {
        return password_hash($password, $this->algorithm);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * True when the stored hash was made with a weaker algorithm/parameters
     * than this hasher currently uses — the cue to re-hash on next login.
     */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm);
    }
}
