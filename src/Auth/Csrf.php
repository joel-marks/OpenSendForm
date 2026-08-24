<?php

declare(strict_types=1);

namespace OpenSendForm\Auth;

/**
 * Per-session CSRF token.
 *
 * A single random token is minted on first use and stored in the session;
 * every admin POST must echo it back. validate() compares in constant time
 * and fails closed on a missing or mismatched token.
 */
final class Csrf
{
    /** Session key holding the token. */
    private const SESSION_KEY = 'csrf.token';

    /** The form field / header name carrying the token. */
    public const FIELD = '_csrf';

    private SessionInterface $session;

    public function __construct(SessionInterface $session)
    {
        $this->session = $session;
    }

    /**
     * The current token, minting one on first use.
     */
    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    /**
     * True when the submitted token matches the session token.
     */
    public function validate(?string $submitted): bool
    {
        $stored = $this->session->get(self::SESSION_KEY);

        return is_string($stored)
            && $stored !== ''
            && is_string($submitted)
            && hash_equals($stored, $submitted);
    }
}
