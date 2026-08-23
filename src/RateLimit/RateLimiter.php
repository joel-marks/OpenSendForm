<?php

declare(strict_types=1);

namespace OpenSendForm\RateLimit;

use OpenSendForm\Clock\Clock;
use OpenSendForm\Storage\Database;

/**
 * Fixed-window rate limiter backed by the rate_counters table.
 *
 * Time is divided into aligned windows of a given width. Each call to
 * hit() increments the counter for the current window of a bucket and
 * reports whether the caller is still within its limit. Buckets are free
 * strings (e.g. 'ip:203.0.113.7', 'form:osf_ab...').
 *
 * Portability: the increment is a read-then-write rather than a dialect
 * -specific UPSERT so the same code runs on sqlite and mysql. Under
 * concurrency this can under-count slightly; that is an acceptable trade
 * for a shared-hosting abuse filter.
 */
final class RateLimiter
{
    private Database $db;
    private Clock $clock;

    public function __construct(Database $db, Clock $clock)
    {
        $this->db = $db;
        $this->clock = $clock;
    }

    /**
     * Register one hit against a bucket and report whether it is allowed.
     *
     * @param string $bucket        Counter name, e.g. 'ip:<addr>'.
     * @param int    $limit         Maximum hits permitted per window.
     * @param int    $windowSeconds Window width in seconds.
     *
     * @return bool True if this hit is within the limit; false if the limit
     *              has been exceeded for the current window.
     */
    public function hit(string $bucket, int $limit, int $windowSeconds): bool
    {
        $now = $this->clock->now();
        $windowStart = $now - ($now % $windowSeconds);

        // Opportunistically drop this bucket's stale windows. Scoping the
        // prune to the same bucket keeps it safe when buckets of different
        // window widths share the table.
        $this->db->execute(
            'DELETE FROM rate_counters WHERE bucket = :bucket AND window_start < :window_start',
            ['bucket' => $bucket, 'window_start' => $windowStart]
        );

        $row = $this->db->fetchOne(
            'SELECT count FROM rate_counters WHERE bucket = :bucket AND window_start = :window_start',
            ['bucket' => $bucket, 'window_start' => $windowStart]
        );

        if ($row === null) {
            $this->db->execute(
                'INSERT INTO rate_counters (bucket, window_start, count) VALUES (:bucket, :window_start, 1)',
                ['bucket' => $bucket, 'window_start' => $windowStart]
            );
            $count = 1;
        } else {
            $this->db->execute(
                'UPDATE rate_counters SET count = count + 1 WHERE bucket = :bucket AND window_start = :window_start',
                ['bucket' => $bucket, 'window_start' => $windowStart]
            );
            $count = (int) $row['count'] + 1;
        }

        return $count <= $limit;
    }
}
