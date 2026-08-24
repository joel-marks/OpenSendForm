-- Migration 005: mail-delivery state on submissions.
-- Tracks the synchronous-first send plus operator-driven retry. One column
-- per statement so the ALTER TABLE ADD COLUMN form stays portable across
-- sqlite and mysql. attempts counts send attempts made; last/next_attempt_at
-- are portable 'Y-m-d H:i:s' UTC text; last_error holds the truncated failure
-- reason for the most recent failed attempt.
ALTER TABLE submissions ADD COLUMN attempts INTEGER NOT NULL DEFAULT 0;
ALTER TABLE submissions ADD COLUMN last_attempt_at TEXT NULL;
ALTER TABLE submissions ADD COLUMN next_attempt_at TEXT NULL;
ALTER TABLE submissions ADD COLUMN last_error TEXT NULL;
