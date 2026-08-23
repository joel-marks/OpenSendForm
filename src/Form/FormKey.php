<?php

declare(strict_types=1);

namespace OpenSendForm\Form;

/**
 * Generator for public form keys.
 *
 * A form key is a PUBLIC identifier that appears in client-site HTML. It
 * is not a secret and is never hashed; it is stored plain. Security comes
 * from origin allowlists, rate limits and (later) Turnstile.
 *
 * Format: the literal prefix "osf_" followed by 32 lowercase hex
 * characters (16 random bytes) drawn from a cryptographically secure
 * source.
 */
final class FormKey
{
    public const PREFIX = 'osf_';

    /**
     * Generate a new form key, e.g. "osf_1a2b...".
     */
    public static function generate(): string
    {
        return self::PREFIX . bin2hex(random_bytes(16));
    }
}
