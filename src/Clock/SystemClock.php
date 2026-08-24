<?php

declare(strict_types=1);

namespace OpenSendForm\Clock;

/**
 * The real clock, backed by the system time.
 */
final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
