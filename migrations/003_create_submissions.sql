-- Migration 003: submissions table.
-- One row per received submission. Metadata is always stored; the
-- message content column is populated only when the owning form's
-- store_content toggle is enabled. Portable types only.
CREATE TABLE IF NOT EXISTS submissions (
    id         INTEGER PRIMARY KEY,
    form_id    INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    remote_ip  TEXT NOT NULL,
    origin     TEXT,
    user_agent TEXT,
    status     TEXT NOT NULL DEFAULT 'received',
    content    TEXT
);

CREATE INDEX IF NOT EXISTS idx_submissions_form_created
    ON submissions (form_id, created_at);
