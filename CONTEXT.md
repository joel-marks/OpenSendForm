# OpenSendForm — current state

Last updated: 2026-08-23 (Increment 1, Claude Code)

## Status
Domain data model in place. On top of the Increment 0 skeleton there is
now a forms table and a submissions table, a form/API-key model with a
repository, a submission repository honouring the per-form content
toggle and retention purge, and a `bin/osf` CLI to manage forms. Test
suite green (30 tests). No HTTP endpoints beyond `/health` yet.

## Product definition
Free, open-source, self-hostable form-to-email service for shared
cPanel/PHP hosting. One installation serves many websites via embedded
snippet + JS posting to a central endpoint. Validated, abuse-filtered,
relayed by authenticated SMTP to the site owner.

## Decisions locked
- Name: OpenSendForm. Domains opensendform.com/.org are project/docs
  site only — no installation depends on them at runtime.
- Stack: PHP 8.1+ / Slim 4 / Composer / PHPMailer / SQLite default
  (MySQL optional via PDO). Server-rendered admin UI, no JS frameworks.
- Distribution: release zip with vendored dependencies + browser-based
  installer (upload, extract, visit /install, self-lock).
- Admin panel: required. Argon2id passwords, TOTP 2FA, CSRF tokens,
  rate-limited login, hardened sessions.
- Public endpoint defences: origin allowlists, Turnstile (optional per
  form), honeypot, minimum time-to-submit, per-IP and per-key rate
  limits, payload caps, strict validation, email header injection
  prevention.
- Form keys are PUBLIC identifiers (they appear in client-site HTML):
  stored plain, never hashed. A "form" is the unit of configuration —
  one record per embedded form carrying its own key, recipient, origins
  and toggles.
- Mail policy: always From: the service domain, Reply-To: the submitter.
  Never From: the submitter (DMARC).
- Submitter email verification REJECTED; replaced by MX/DNS check,
  client-side typo suggestion, optional disposable-domain blocklist.
- No "send me a copy" feature ever (backscatter vector).
- Storage: metadata always stored; message-content storage is a
  per-form toggle with configurable retention purge.
- Portability: all SQL must run identically on sqlite and mysql —
  portable types (TEXT/INTEGER), no dialect-specific syntax. Date
  arithmetic for retention is done in PHP, not SQL.
- Timestamps stored as UTC `Y-m-d H:i:s` TEXT (portable, lexically
  sortable — safe for range comparisons).
- Dev email: Mailpit only.

## What exists now
Increment 0 (unchanged): composer project, `public/index.php`,
`src/AppFactory.php`, `src/Routes.php` (only `GET /health`),
`src/Version.php`, `src/Config.php`, `src/Storage/Database.php`,
`src/Storage/MigrationRunner.php`, `migrations/001_*`, `var/`.

Increment 1 additions:
- `migrations/002_create_forms.sql` — forms table: id, form_key (UNIQUE),
  name, recipient_email, allowed_origins (JSON array TEXT), store_content
  (INTEGER, default 0), retention_days (INTEGER, default 30), is_active
  (INTEGER, default 1), created_at, updated_at.
- `migrations/003_create_submissions.sql` — submissions table: id,
  form_id, created_at, remote_ip, origin (nullable), user_agent
  (nullable), status (default 'received'), content (nullable JSON,
  populated only when the form's store_content is 1). Index on
  (form_id, created_at).
- `src/Form/FormKey.php` — `generate()` returns `osf_` + 32 lowercase
  hex chars from `random_bytes(16)`.
- `src/Form/FormRepository.php` — createForm (generates key, validates
  recipient via filter_var, normalises + validates origins, defaults
  store_content=0/retention=30/active=1), findByKey (active only),
  findById, listForms (newest first), setActive, normaliseOrigins.
  Origins normalised to scheme+host+optional port (lower-cased, no path/
  query/fragment/credentials, trailing slash stripped, dedup); invalid
  origins and emails throw InvalidArgumentException. Prepared statements
  throughout; rows hydrated to typed PHP values (origins JSON-decoded).
- `src/Submission/SubmissionRepository.php` — recordSubmission (stores
  content only when the owning form has store_content=1, else NULL),
  purgeExpired (per-form cutoff computed in PHP, portable DELETE),
  findById.
- `bin/osf` — executable PHP CLI (no libraries): form:create
  (--name/--recipient/--origin repeatable, prints generated key),
  form:list, form:enable ID, form:disable ID; usage text on no/unknown
  command. Connects via Config DSN and applies pending migrations first.
- Housekeeping: composer `test` script now runs
  `XDEBUG_MODE=off phpunit` to silence Xdebug step-debug warnings.

## Known gaps / not built (by design this sprint)
- No submission HTTP endpoint, validation/abuse middleware, mail
  sending, Turnstile, admin UI or installer.
- No repository method to toggle store_content or retention_days yet
  (arrives with the admin UI); defaults are applied on create.

## Open items
- Increment 2 (submission endpoint + validation/abuse middleware) next.

## Planned increment sequence (subject to revision)
0. Composer/Slim skeleton, PHPUnit harness, SQLite storage. — DONE
1. Schema + form/API-key model. — DONE
2. Submission endpoint + validation/abuse middleware stack.
3. SMTP relay (PHPMailer) with retry.
4. Turnstile integration.
5. Admin panel + auth (argon2id, TOTP, CSRF).
6. Browser installer + environment autodetection.
7. Embed snippet + JS.
8. Synthetic monitoring + alerting.
