<?php

declare(strict_types=1);

namespace OpenSendForm\Auth;

/**
 * The result of a TOTP/recovery-code verification attempt.
 *
 * - RateLimited: too many recent attempts (per-admin or per-IP bucket).
 * - Invalid:     no pending login, or the code/recovery code did not match.
 * - Success:     login completed.
 */
enum TotpOutcome: string
{
    case RateLimited = 'rate_limited';
    case Invalid = 'invalid';
    case Success = 'success';
}
