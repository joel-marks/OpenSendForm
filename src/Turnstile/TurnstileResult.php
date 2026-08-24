<?php

declare(strict_types=1);

namespace OpenSendForm\Turnstile;

/**
 * The outcome of verifying a Turnstile token with Cloudflare.
 *
 *  - VALID       Cloudflare positively confirmed the token.
 *  - INVALID     Cloudflare positively rejected the token — the one case
 *                that rejects a submission (400 turnstile_failed).
 *  - UNAVAILABLE the verify API was unreachable, timed out or returned
 *                malformed output. The pipeline FAILS OPEN on this: the
 *                submission proceeds and the other defences still apply.
 */
enum TurnstileResult
{
    case VALID;
    case INVALID;
    case UNAVAILABLE;
}
