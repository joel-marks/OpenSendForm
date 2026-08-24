# OpenSendForm — current state

Last updated: 2026-08-24 (fix/mail-content-lifecycle, Claude Code)

## Status
The public submission endpoint is live end-to-end and RELAYS BY EMAIL.
On top of the Increment 1 data model there is a versioned v1 API (token
endpoint, CORS preflights, `POST /v1/form/{form_key}/submit`) driven by an
ordered validation/abuse pipeline. Submissions that pass are stored, then a
single in-request SMTP send is attempted; failures are retried by an
operator cron. This sprint resolved both open Increment-3 questions: the
content-storage lifecycle and a dedicated MAIL_ENABLED switch. Test suite
green (127 tests). CI runs it on every PR/push.

## Product definition
Free, open-source, self-hostable form-to-email service for shared
cPanel/PHP hosting. One installation serves many websites via embedded
snippet + JS posting to a central endpoint. Validated, abuse-filtered,
relayed by authenticated SMTP to the site owner.

## Decisions locked
- Name: OpenSendForm. Stack: PHP 8.1+ / Slim 4 / Composer / PHPMailer /
  SQLite default (MySQL optional via PDO). Server-rendered admin UI later.
- All SQL portable across sqlite + mysql (portable types; date arithmetic
  in PHP, not SQL). Timestamps stored as UTC `Y-m-d H:i:s` TEXT.
- Form keys are PUBLIC identifiers, stored plain. A "form" is the unit of
  config (key, recipient, origins, toggles).
- Response contract (FROZEN — embed JS builds against it): JSON only.
  Success `{"ok":true}`; failure `{"ok":false,"error":{"code","message"}}`.
  HTTP: 200 ok, 400 validation, 403 key/origin, 405 method, 413 too large,
  429 rate limited.
- Bot-facing checks fail SILENTLY (normal `{"ok":true}`, nothing stored):
  filled honeypot; missing / forged / too-young token. EXCEPTION: an
  authentic but EXPIRED token returns an honest `400 token_expired`.
- Rate limiting uses REMOTE_ADDR only (trusted-proxy support is future work).
- Storage: metadata always stored. Message content is always stored too, as
  the in-flight delivery payload — regardless of the form's store_content
  toggle. `store_content` means "retain content after successful delivery":
  on `sent`, content is cleared unless the toggle is on; `failed`/`dead`
  submissions always keep content (for retry/operator recovery) until the
  normal retention purge. See Mail relay section and QUESTIONS.md item 1.

## Mail relay (Increment 3 + follow-up)
- Mail policy (hard rules): From is ALWAYS the configured service address;
  Reply-To is the submitter's `email` field only when syntactically valid;
  no other submitter data reaches any header. Subject is the form name with
  control chars stripped; body is a plain-text field listing with names and
  values sanitised and capped. `Mail\MessageBuilder` enforces this and is the
  last line of defence alongside (not instead of) PHPMailer's own checks.
- `Mail\MailerInterface` (`send(to, replyTo?, subject, body)`, throws on
  failure) with `PhpMailerMailer` (SMTP from Config, 15s timeout, exceptions
  on, `html5` address validator so `noreply@localhost` is accepted) and a
  `tests/Support/FakeMailer` double (records calls, scriptable to fail).
- `Mail\DeliveryService::attemptDelivery(id)` loads submission+form, builds
  the message, sends. Success → status `sent`, then content is cleared via
  `SubmissionRepository::clearContent()` unless the form's store_content is
  on. Failure → `failed`, attempts+1, last_error, next_attempt_at per backoff
  (content untouched, so a retry still has it) — unless attempts reach
  MAIL_MAX_ATTEMPTS, then `dead` (content still untouched). `retryDue(now?)`
  re-attempts every `failed` row whose next_attempt_at is due (fake-clock
  testable) and returns a count summary. Backoff = MAIL_RETRY_BACKOFF_MINUTES
  list, last value repeating.
- Synchronous-first, no queue: `Submit\Stages\DeliveryStage` is the terminal
  pipeline stage. StoreStage now returns null (stashing the new id on the
  context, content always persisted) and DeliveryStage makes at most one send
  then ALWAYS returns success, so a delivery failure never changes the
  submitter's `{"ok":true}`. Skips (leaving status `received`) when no mailer
  is wired, MAIL_ENABLED is falsy, SMTP_HOST is empty, or nothing was stored.
- Config: MAIL_ENABLED (default '1', typed `mailEnabled()`; primary delivery
  switch — SMTP_HOST emptiness is now only a secondary guard, kept for the
  fromValues()-only storage-only seam), SMTP_USER, SMTP_PASS, SMTP_ENCRYPTION
  (none|starttls|smtps, default none), MAIL_FROM_ADDRESS (noreply@localhost),
  MAIL_FROM_NAME (OpenSendForm), MAIL_MAX_ATTEMPTS (5),
  MAIL_RETRY_BACKOFF_MINUTES (1,5,30,120). `Config::fromValues()` honours
  explicit empties (env parser still protects defaults); front controller
  wires a real mailer only when `mailEnabled() && smtpHost() !== ''`.
- Migration 005 adds submissions.attempts / last_attempt_at / next_attempt_at
  / last_error (one portable ADD COLUMN each).
- `bin/osf`: `mail:retry` (runs retryDue, prints summary, exit 0 — for cron),
  `mail:test --to=ADDRESS`, `submissions:list [--status=X]` (metadata only,
  never content).

## Submission pipeline order (enforced + tested)
method/body size → field hygiene (+ reserved `_osf_token/_osf_hp`) → form
lookup by URL key → origin allowlist (sets CORS) → per-IP then per-form rate
limits → honeypot → token → email (syntax + MX/A, if `email` field present) →
store (non-terminal, content always persisted) → delivery (terminal, always
succeeds). Locked by SubmitPipelineOrderTest.

## What exists now
Increments 0–2 unchanged (see HISTORY). Increment 3 added `src/Mail/*`
(MessageBuilder, BuiltMessage, MailerInterface, PhpMailerMailer,
DeliveryService), `Submit/Stages/DeliveryStage`, migration 005, the Config
mail keys + fromValues, SubmissionRepository mail-state methods
(markSent/markFailed/markDead/findDueForRetry/listSummaries), the three
`bin/osf` commands, and phpmailer/phpmailer ^6 (the only new dependency).
This sprint (fix/mail-content-lifecycle) changed the meaning of
`store_content` (see Decisions locked), added
`SubmissionRepository::clearContent()`, and added `Config::mailEnabled()` /
`MAIL_ENABLED`. No new dependencies.

## Known gaps / not built (by design this sprint)
- Turnstile, disposable-domain blocklist, admin UI, installer, embed
  snippet/JS, trusted-proxy IP handling — all future increments.
- No repository method to toggle store_content/retention yet (admin UI).
- DKIM/SPF guidance deferred to the installer increment.

## Open items
- QUESTIONS.md: both Increment-3 items now RESOLVED (2026-08-24).
- Increment 4 (Turnstile) next.

## Planned increment sequence (subject to revision)
0. Composer/Slim skeleton, PHPUnit harness, SQLite storage. — DONE
1. Schema + form/API-key model. — DONE
2. Submission endpoint + validation/abuse middleware stack. — DONE
3. SMTP relay (PHPMailer) with retry. — DONE
4. Turnstile integration.
5. Admin panel + auth (argon2id, TOTP, CSRF).
6. Browser installer + environment autodetection.
7. Embed snippet + JS.
8. Synthetic monitoring + alerting.
