-- Migration 001: migration bookkeeping table only.
-- Tracks which numbered migrations have been applied. No domain tables
-- are created here; those arrive in later increments.
CREATE TABLE IF NOT EXISTS schema_migrations (
    version    INTEGER PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
