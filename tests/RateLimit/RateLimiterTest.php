<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\RateLimit;

use OpenSendForm\RateLimit\RateLimiter;
use OpenSendForm\Storage\Database;
use OpenSendForm\Storage\MigrationRunner;
use OpenSendForm\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    private Database $db;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->db = Database::connect('sqlite::memory:');
        (new MigrationRunner($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->clock = new FixedClock(1_700_000_000);
    }

    private function limiter(): RateLimiter
    {
        return new RateLimiter($this->db, $this->clock);
    }

    public function testAllowsUpToLimitThenBlocksWithinWindow(): void
    {
        $limiter = $this->limiter();

        // Limit of 3 per 60s window.
        self::assertTrue($limiter->hit('ip:x', 3, 60));  // 1
        self::assertTrue($limiter->hit('ip:x', 3, 60));  // 2
        self::assertTrue($limiter->hit('ip:x', 3, 60));  // 3
        self::assertFalse($limiter->hit('ip:x', 3, 60)); // 4 -> blocked
        self::assertFalse($limiter->hit('ip:x', 3, 60)); // still blocked
    }

    public function testCounterResetsInNextWindow(): void
    {
        $limiter = $this->limiter();

        self::assertTrue($limiter->hit('ip:x', 1, 60));
        self::assertFalse($limiter->hit('ip:x', 1, 60));

        // Advance into the next aligned window.
        $this->clock->advance(60);

        self::assertTrue($limiter->hit('ip:x', 1, 60));
    }

    public function testBucketsAreIndependent(): void
    {
        $limiter = $this->limiter();

        self::assertTrue($limiter->hit('ip:a', 1, 60));
        self::assertFalse($limiter->hit('ip:a', 1, 60));

        // A different bucket has its own counter.
        self::assertTrue($limiter->hit('ip:b', 1, 60));
    }

    public function testWindowWidthsAreIndependentAcrossBuckets(): void
    {
        $limiter = $this->limiter();

        // A short (per-minute) bucket and a long (per-hour) bucket coexist.
        self::assertTrue($limiter->hit('ip:x', 1, 60));
        self::assertTrue($limiter->hit('form:y', 2, 3600));

        // Advancing one minute rolls the per-minute window but not the hour.
        $this->clock->advance(60);

        self::assertTrue($limiter->hit('ip:x', 1, 60));    // fresh minute window
        self::assertTrue($limiter->hit('form:y', 2, 3600)); // 2nd hit, still under 2? -> equals limit, allowed
        self::assertFalse($limiter->hit('form:y', 2, 3600)); // 3rd hit -> blocked
    }

    public function testPrunesStaleWindowsForBucket(): void
    {
        $limiter = $this->limiter();

        $limiter->hit('ip:x', 5, 60);
        self::assertSame(1, $this->rowCount());

        // Next window: the previous window's row is pruned opportunistically.
        $this->clock->advance(60);
        $limiter->hit('ip:x', 5, 60);

        self::assertSame(1, $this->rowCount());
    }

    private function rowCount(): int
    {
        $row = $this->db->fetchOne('SELECT COUNT(*) AS c FROM rate_counters');

        return (int) $row['c'];
    }
}
