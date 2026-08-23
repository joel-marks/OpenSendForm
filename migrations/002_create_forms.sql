-- Migration 002: forms table.
-- One row per embedded form. A form is the unit of configuration: it
-- carries its own public key, recipient, allowed origins and toggles.
-- Portable types only (TEXT/INTEGER) so the schema is identical on
-- sqlite and mysql.
CREATE TABLE IF NOT EXISTS forms (
    id              INTEGER PRIMARY KEY,
    form_key        TEXT NOT NULL UNIQUE,
    name            TEXT NOT NULL,
    recipient_email TEXT NOT NULL,
    allowed_origins TEXT NOT NULL,
    store_content   INTEGER NOT NULL DEFAULT 0,
    retention_days  INTEGER NOT NULL DEFAULT 30,
    is_active       INTEGER NOT NULL DEFAULT 1,
    created_at      TEXT NOT NULL,
    updated_at      TEXT NOT NULL
);
