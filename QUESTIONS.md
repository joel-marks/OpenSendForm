# Open questions for the architect

## Increment 6b

1. **Branch base: 6b was built on `feature/increment-6a-installer`, not `main`.
   FLAG FOR MERGE ORDER (not blocking).**
   The 6b task prompt said to branch from `main`, but Increment 6a (the browser
   installer engine) has not been merged to `main` yet — its `src/Install/*`,
   the atomic config writer, `Config::load/fromFile`, `DB_USER/DB_PASS` and the
   installer done screen live only on the 6a branch. Task 4 (done-screen +
   dashboard handoff) and the "config write-back via the existing atomic writer"
   requirement both depend on 6a. Branching 6b from `main` would have dropped all
   of that. Decision: branch 6b off `feature/increment-6a-installer`. Merge 6a
   first, then 6b (or merge 6b, which contains 6a's commits, as one). No code
   question here — just merge-order awareness.


## Increment 2

1. **/v1/submit CORS preflight origin decision. RESOLVED (2026-08-24).**
   The any-active-form preflight match was rejected: it let a caller
   enumerate an installation's registered origins by probing preflights
   with different Origin headers. Fix: the form key moved into the URL
   (`POST /v1/form/{form_key}/submit`), so the preflight resolves the
   specific form and echoes the origin only when that form's own allowlist
   matches — exact, per-form, no enumeration surface. See
   fix/submit-route-form-key and the HISTORY.md entry for 2026-08-24.

## Increment 3

1. **Delivery content vs. the `store_content` toggle. RESOLVED (2026-08-24).**
   Decision: option (a) — content is always stored at submission time as the
   in-flight delivery payload; `store_content` now governs retention after a
   successful send (cleared on `sent` when off, kept when on; `failed`/`dead`
   always keep it for retry/recovery until the retention purge). See
   fix/mail-content-lifecycle and the HISTORY.md entry for 2026-08-24.

   Original question, for context:
   `DeliveryService::attemptDelivery()` loads the submission row and builds the
   email from its stored `content` (as the sprint prompt specifies). But
   `store_content` defaults OFF and, when off, the `content` column is NULL —
   so the relayed email lists no fields, and a retry has nothing to resend.
   For a form-to-email service this is the wrong default for the core use
   case. Options for the architect:
   (a) store submitted content unconditionally for pending delivery and let
       `store_content` govern *retention* (purge/redact after a successful
       send) rather than initial storage — cleanest, but changes the meaning
       of the existing toggle and the `testStoreContentOffKeepsMetadataOnly`
       assertion;
   (b) add a separate, always-populated delivery-payload column consumed and
       cleared by the mailer, leaving `store_content` about admin retention;
   (c) have the in-request first attempt use the live posted fields (always
       available) and accept that retries of `store_content=0` forms carry
       metadata only.
   Implemented as specified for now (reads `content`; empty when off). The
   local test recipe enables `store_content` so the email shows fields.

2. **Storage-only requires an empty `SMTP_HOST`, which `fromEnvironment()`
   cannot express. RESOLVED (2026-08-24).**
   Decision: added a dedicated `MAIL_ENABLED` config flag (env-overridable,
   default '1'). It is now the primary delivery switch — delivery is
   attempted only when `MAIL_ENABLED` is truthy AND `SMTP_HOST` is
   non-empty — with the host check kept only as a secondary guard. See
   fix/mail-content-lifecycle and the HISTORY.md entry for 2026-08-24.

   Original question, for context:
   The empty-value fallback in `Config::fromEnvironment()` keeps the
   `SMTP_HOST` default ('localhost') even when the env sets it to '' (locked
   by `testBlankAndFalseValuesFallBackToDefaults`). Storage-only operation
   therefore can't be selected by blanking `SMTP_HOST` through the
   environment. Handled by: the front controller passes a real mailer only
   when `SMTP_HOST !== ''`, and `Config::fromValues()` (explicit construction,
   empties honoured) exists for tests/programmatic callers. If operators are
   expected to disable mail via env, a dedicated `MAIL_ENABLED` flag (empty by
   default) would be clearer than overloading `SMTP_HOST` — flagging for the
   installer increment.
