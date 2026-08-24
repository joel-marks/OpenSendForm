<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Support;

use OpenSendForm\Auth\PasswordHasher;

/**
 * A PasswordHasher that counts verify() calls, so tests can assert the
 * unknown-email login path still performs a password_verify (constant-time
 * defence against user enumeration). Defaults to bcrypt to keep tests fast.
 */
final class CountingPasswordHasher extends PasswordHasher
{
    private int $verifyCalls = 0;

    public function __construct(string|int|null $algorithm = PASSWORD_BCRYPT)
    {
        parent::__construct($algorithm);
    }

    public function verify(string $password, string $hash): bool
    {
        $this->verifyCalls++;

        return parent::verify($password, $hash);
    }

    public function verifyCalls(): int
    {
        return $this->verifyCalls;
    }
}
