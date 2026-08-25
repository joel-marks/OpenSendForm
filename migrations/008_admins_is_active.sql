-- Migration 008: add an active flag to admins.
-- A deactivated admin (is_active = 0) can no longer sign in and any live
-- session is invalidated on its next request. There is no delete action by
-- design (account deletion is deferred), so deactivation is how an operator
-- is retired. Defaults to 1 so every existing admin stays active. Portable
-- INTEGER type so the schema is identical on sqlite and mysql.
ALTER TABLE admins ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1;
