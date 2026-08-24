<?php

declare(strict_types=1);

namespace OpenSendForm\Turnstile;

/**
 * Verifies a Cloudflare Turnstile client token against the siteverify API.
 *
 * Abstracted behind an interface so tests can substitute a deterministic
 * fake and never touch the real Cloudflare endpoint.
 */
interface TurnstileVerifierInterface
{
    /**
     * Verify a client token for a form's secret.
     *
     * @param string $secret   The form's Turnstile secret key.
     * @param string $token    The client token (reserved field _osf_cf).
     * @param string $remoteIp The submitter's IP; may be '' (omitted).
     *
     * @return TurnstileResult VALID / INVALID / UNAVAILABLE. Implementations
     *         MUST return UNAVAILABLE (never INVALID) on any transport error,
     *         timeout or malformed response so callers can fail open.
     */
    public function verify(string $secret, string $token, string $remoteIp): TurnstileResult;
}
