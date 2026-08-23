<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Support;

use OpenSendForm\Clock\Clock;

/**
 * A deterministic clock for tests: reports a fixed time that the test can
 * set or advance to exercise time-dependent behaviour without sleeping.
 */
final class FixedClock implements Clock
{
    private int $now;

    public function __construct(int $now = 1_700_000_000)
    {
        $this->now = $now;
    }

    public function now(): int
    {
        return $this->now;
    }

    public function set(int $now): void
    {
        $this->now = $now;
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }
}
