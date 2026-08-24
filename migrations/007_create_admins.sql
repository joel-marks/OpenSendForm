-- Migration 007: admins table.
-- One row per administrator. Passwords are hashed (argon2id where the PHP
-- build supports it, else bcrypt); TOTP is optional per admin and only
-- enforced once totp_enabled is set. Recovery codes are stored as a JSON
-- array of password_hash'es (never plaintext). Portable types only
-- (TEXT/INTEGER) so the schema is identical on sqlite and mysql.
CREATE TABLE IF NOT EXISTS admins (
    id             INTEGER PRIMARY KEY,
    email          TEXT NOT NULL UNIQUE,
    display_name   TEXT NOT NULL,
    password_hash  TEXT NOT NULL,
    totp_secret    TEXT NULL,
    totp_enabled   INTEGER NOT NULL DEFAULT 0,
    recovery_codes TEXT NULL,
    created_at     TEXT NOT NULL,
    updated_at     TEXT NOT NULL,
    last_login_at  TEXT NULL
);
