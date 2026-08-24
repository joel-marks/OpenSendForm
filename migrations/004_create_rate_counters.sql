-- Migration 004: rate_counters table.
-- Fixed-window rate-limit counters. One row per (bucket, window_start):
-- bucket names look like 'ip:<addr>' or 'form:<key>'; window_start is the
-- unix timestamp of the window's aligned start. Expired windows are pruned
-- opportunistically by the RateLimiter. Portable types only.
CREATE TABLE IF NOT EXISTS rate_counters (
    bucket       TEXT NOT NULL,
    window_start INTEGER NOT NULL,
    count        INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (bucket, window_start)
);
