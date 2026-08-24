<?php

declare(strict_types=1);

namespace OpenSendForm\Auth;

/**
 * The result of a password login attempt.
 *
 * - RateLimited: too many recent attempts (per-IP or per-email bucket).
 * - Invalid:     unknown email OR wrong password (never distinguished, to
 *                avoid user enumeration).
 * - NeedsTotp:   password accepted; a valid TOTP/recovery code is required
 *                to complete login.
 * - Success:     fully authenticated (no TOTP configured for this admin).
 */
enum LoginOutcome: string
{
    case RateLimited = 'rate_limited';
    case Invalid = 'invalid';
    case NeedsTotp = 'needs_totp';
    case Success = 'success';
}
