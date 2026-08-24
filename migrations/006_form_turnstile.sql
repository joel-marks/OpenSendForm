-- Migration 006: optional per-form Cloudflare Turnstile.
-- Turnstile is enabled for a form only when BOTH columns are non-empty; a
-- NULL/empty in either means the challenge is skipped (there is no global
-- switch). The secret is never exposed by any endpoint. One ADD COLUMN per
-- statement keeps the ALTER portable across sqlite and mysql.
ALTER TABLE forms ADD COLUMN turnstile_sitekey TEXT NULL;
ALTER TABLE forms ADD COLUMN turnstile_secret TEXT NULL;
