<?php

declare(strict_types=1);

namespace OpenSendForm\Clock;

/**
 * A source of the current time.
 *
 * Injected wherever timing matters (rate-limit windows, submit-token age)
 * so tests can advance time deterministically instead of sleeping or
 * hitting the wall clock.
 */
interface Clock
{
    /**
     * The current time as a unix timestamp (whole seconds).
     */
    public function now(): int;
}
