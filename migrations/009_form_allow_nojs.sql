-- Migration 009: per-form no-JS submission policy.
-- allow_nojs = 0 (default): an HTML-negotiated (no-JS) POST with a missing
-- or invalid submit token gets an honest error page and is never stored —
-- the form requires JavaScript to submit. allow_nojs = 1: a *missing* token
-- on an HTML-negotiated POST skips the token check (the min-time bot check
-- is knowingly waived for this subset); all other stages still apply.
-- Portable INTEGER type so the schema is identical on sqlite and mysql.
ALTER TABLE forms ADD COLUMN allow_nojs INTEGER NOT NULL DEFAULT 0;
