# OpenSendForm — sprint history (append-only)

## 2026-08-20 — Sprint 0: bootstrap
- Branch: main (direct; pre-code, human-pushed)
- Repo initialised, devcontainer (PHP 8.1 + Node 20 + Claude Code +
  Mailpit sidecar) added, standing orders (CLAUDE.md) and state files
  seeded. No application code. No tests.

## 2026-08-21 — Devcontainer hotfix (PR #1)
- Branch: fix/devcontainer-yarn-key → merged to main via PR #1.
- Removed a stale Yarn apt repository/signing key from the base image
  Dockerfile that broke `apt-get update` during container build.
  Recorded here for completeness; work predates Increment 0.

## 2026-08-21 — Increment 0: application skeleton
- Branch: feature/increment-0-skeleton (off latest main).
- Housekeeping: tracked `.devcontainer/devcontainer-lock.json` and the
  updated `.claude/settings.json` (adds `git checkout` permission).
- Composer project `opensendform/opensendform` (MIT, PHP >=8.1). Deps:
  slim/slim ^4, slim/psr7, php-di/php-di, phpunit (dev) — exactly the
  authorised set. Scripts: `test` (phpunit), `serve` (php built-in
  server on :8080 from public/).
- Front controller `public/index.php` → `src/AppFactory.php`, which
  builds the Slim app + php-di container and is drivable from tests via
  `$app->handle()` (no HTTP serving in the factory).
- `src/Config.php`: defaults + env overrides (APP_ENV, SMTP_HOST,
  SMTP_PORT, DB_DSN); default DB `sqlite:var/data/opensendform.sqlite`;
  blank/false env values fall back to defaults; no secrets in code.
- Storage: `src/Storage/Database.php` (thin PDO wrapper, exceptions on,
  prepared statements only, sqlite + mysql) and
  `src/Storage/MigrationRunner.php` (numbered .sql, ordered, tracked in
  `schema_migrations`, idempotent, per-migration transaction).
  `migrations/001_create_schema_migrations.sql` creates the tracking
  table only — no domain tables.
- One route: `GET /health` → 200 `{"status":"ok","version":"0.0.1"}`;
  version sourced from `src/Version.php` (single constant).
- `var/` contents gitignored, directory kept via `.gitkeep`; vendor/
  remains ignored.
- Tests (PHPUnit, phpunit.xml at root): `/health` via the factory;
  migration runner clean apply + idempotent second run on in-memory
  sqlite; Config env-override behaviour.
- README: added a Development "Getting started" section (reopen in
  container, composer install/test/serve, Mailpit at localhost:8025).
- Test results: **OK — 8 tests, 22 assertions, all green.** Smoke-tested
  `/health` via `php -S` (200 + expected JSON; unknown route → 404).
- Deviations from prompt: none. No new/unauthorised Composer deps.
  QUESTIONS.md unchanged (no blockers).

## 2026-08-23 — Increment 1: schema + form/API-key model
- Branch: feature/increment-1-schema (off latest main).
- Housekeeping: composer `test` script now runs `XDEBUG_MODE=off
  phpunit`, silencing the Xdebug step-debug "could not connect" warnings
  during the phpunit run. (A single warning still emits from composer's
  own process before the script runs; the phpunit run itself is clean.)
- Migration 002 (`forms`): id PK, form_key TEXT NOT NULL UNIQUE, name,
  recipient_email, allowed_origins (JSON array TEXT), store_content
  INTEGER DEFAULT 0, retention_days INTEGER DEFAULT 30, is_active
  INTEGER DEFAULT 1, created_at, updated_at. Portable types only.
- Migration 003 (`submissions`): id PK, form_id, created_at, remote_ip,
  origin (nullable), user_agent (nullable), status DEFAULT 'received',
  content (nullable). Index idx_submissions_form_created on
  (form_id, created_at).
- `src/Form/FormKey.php`: `generate()` → `osf_` + 32 lowercase hex from
  `random_bytes(16)`. Keys are public identifiers, stored plain.
- `src/Form/FormRepository.php`: createForm (key gen + filter_var email
  validation + origin normalisation/validation), findByKey (active
  only), findById, listForms, setActive, normaliseOrigins. Origins
  reduced to scheme+host+optional port (lower-cased, trailing slash
  stripped, path/query/fragment/credentials rejected, deduped);
  http/https only. Prepared statements throughout.
- `src/Submission/SubmissionRepository.php`: recordSubmission (content
  stored only when form.store_content = 1), purgeExpired (per-form
  cutoff computed in PHP, portable DELETE), findById.
- `bin/osf`: executable CLI (plain argv, no libs) — form:create,
  form:list, form:enable ID, form:disable ID; prints generated key on
  create; usage text on no/unknown command. Applies pending migrations
  before dispatch.
- Tests added: forms/submissions migrations apply cleanly + idempotently
  and create the tables/index; FormKey format + 1000-generation
  uniqueness; FormRepository create/find/list/setActive, origin
  normalisation, and rejection of bad emails and bad origins (path,
  no scheme, non-http scheme, query, empty list, empty name), findByKey
  excluding inactive forms; SubmissionRepository content toggle both
  ways and purgeExpired honouring differing retention_days across two
  forms. Existing MigrationRunnerTest updated to expect versions
  [1, 2, 3].
- Test results: **OK — 30 tests, 1074 assertions, all green.** CLI
  smoke-tested (create/list/enable/disable, bad email, bad id, usage).
- Deviations from prompt: none. No new Composer deps. Existing routes
  untouched, no HTTP endpoints added. QUESTIONS.md unchanged (no
  blockers).
## 2026-08-23 — Increment 2: submission endpoint + validation/abuse stack
- Branch: feature/increment-2-submission (off latest main).
- Config: added APP_SECRET (empty by default; dev-only fallback
  'dev-secret-do-not-use' when APP_ENV=dev — the installer generates a
  real secret in production) plus env-overridable caps/timers:
  MAX_BODY_BYTES 65536, MAX_FIELDS 50, MAX_FIELD_NAME_BYTES 100,
  MAX_FIELD_VALUE_BYTES 10240, MIN_SUBMIT_SECONDS 3,
  TOKEN_MAX_AGE_SECONDS 3600, RATE_IP_PER_MINUTE 5, RATE_FORM_PER_HOUR 60.
  Typed accessors added for each.
- Migration 004 (`rate_counters`): bucket TEXT, window_start INTEGER,
  count INTEGER DEFAULT 0, PRIMARY KEY (bucket, window_start). Portable.
- `src/Clock/Clock.php` (+ SystemClock): injectable unix-seconds clock,
  shared by the rate limiter and submit token so tests control time.
- `src/RateLimit/RateLimiter.php`: fixed-window increment-and-check
  (`hit(bucket, limit, windowSeconds)`); read-then-write increment kept
  portable across sqlite/mysql (no dialect UPSERT); prunes the bucket's
  stale windows opportunistically (scoped per-bucket so mixed window
  widths don't evict each other).
- `src/Security/SubmitToken.php`: issue() → '<ts>.<hmac_sha256(ts.form_key,
  APP_SECRET)>'; verify() → VALID/TOO_YOUNG/EXPIRED/INVALID. Signature
  checked (hash_equals, constant-time) before age, so a forgery is always
  INVALID. Injectable clock.
- `src/Validation/DnsChecker.php` interface + SystemDnsChecker (checkdnsrr
  MX, then A) + tests/Support/FakeDnsChecker (never hits the network).
- `src/Http/OriginMatcher.php`: normalise/resolve/match — Origin header,
  falling back to the Referer's origin, compared against the form's
  normalised allowlist. `src/Http/ApiResponse.php`: the frozen JSON
  success/error contract + CORS header helper.
- Pipeline: each stage a small class implementing `Submit\Stage`, wired in
  fixed order by `Submit\SubmitPipeline::create()` — MethodBody →
  FieldHygiene → FormLookup → Origin → RateLimit → Honeypot → Token →
  EmailValidation → Store. Runner returns on the first stage that yields a
  SubmitOutcome (success or error). Bot-facing checks (honeypot, missing/
  forged/too-young token) return a silent {"ok":true} and store nothing;
  an authentic EXPIRED token returns 400 token_expired.
- Routes (v1): GET /v1/form/{form_key}/token (origin-checked, CORS,
  unknown_form/origin_not_allowed); OPTIONS preflights for the token
  endpoint (GET) and /v1/submit (POST); POST /v1/submit runs the pipeline.
  /v1/submit is mapped for non-POST verbs too so a wrong method returns
  the contract's 405 method_not_allowed body rather than Slim's default.
- AppFactory now wires Database, Clock, DnsChecker, the repositories,
  RateLimiter, SubmitToken and the SubmitPipeline into the container;
  create() takes optional Database/DnsChecker/Clock so tests inject an
  in-memory DB, fake DNS and a fixed clock.
- Tests added (86 total): Config defaults/overrides + APP_SECRET dev
  fallback; SubmitToken all four verdicts incl. wrong-key/wrong-secret/
  malformed; RateLimiter fill/reset/independent-buckets/mixed-widths/
  prune; OriginMatcher; pipeline stage-order lock; and full endpoint
  coverage through the app factory — happy path stores + returns ok, JSON
  body, store_content on/off, honeypot/missing/forged/too-young token all
  silent-success-and-store-nothing, expired → token_expired, every honest
  rejection code with its status, per-IP and per-form rate limits with
  clock roll-over, and the order proof (bad origin + filled honeypot →
  origin_not_allowed). Existing migration tests updated to versions
  [1, 2, 3, 4] and a rate_counters existence assertion added.
- Test results: **OK — 86 tests, 1222 assertions, all green.**
- Deviations from prompt: none functional; no new Composer deps.
  Clarification logged to QUESTIONS.md re: the /v1/submit CORS *preflight*,
  where the form key is not yet known — see that file.

## 2026-08-24 — Fix: move form key into the submit URL (resolves QUESTIONS.md item)
- Branch: fix/submit-route-form-key (off latest main).
- Architect's decision on the open preflight question: the any-active-form
  match was rejected as an origin-enumeration risk (a bad actor could probe
  which origins are registered anywhere in the installation). Instead the
  form key moved out of the body and into the URL so every check —
  including the preflight — resolves one specific form.
- `POST /v1/submit` → `POST /v1/form/{form_key}/submit`. `_osf_key` removed
  as a reserved body field; reserved fields are now just `_osf_token` and
  `_osf_hp`. `SubmitContext` takes the form key from the constructor
  (populated from the route, not from parsed body fields); FieldHygieneStage
  no longer reads or sets it. FormLookupStage is otherwise unchanged — it
  still consumes `$context->formKey`, only the source changed.
- `OPTIONS /v1/form/{form_key}/submit` now resolves the specific form by key
  (mirroring the existing token preflight) and echoes
  `Access-Control-Allow-Origin` only if that form's allowlist matches the
  request's Origin/Referer. Unknown/inactive key or a disallowed origin: no
  ACAO header, same as before. `FormRepository::isOriginAllowedByAnyActiveForm()`
  and its call site were deleted outright — no remaining callers.
  All other routes are still mapped for non-POST verbs so a wrong method on
  the new path still returns the contract's 405 method_not_allowed.
- Pipeline order, stage classes, and all behaviour (silent bot failures,
  honest expired-token error, status codes) are unchanged; only where the
  form key comes from moved.
- Tests: `tests/Submit/SubmitEndpointTest.php` updated to hit
  `/v1/form/{form_key}/submit` and drop `_osf_key` from submitted fields;
  added two tests for the new preflight semantics — unknown form key
  withholds ACAO, and a second form's allowed origin is not echoed for the
  first form's preflight (proves no cross-form enumeration). No other test
  files needed changes (`isOriginAllowedByAnyActiveForm` had no direct
  test coverage).
- Test results: **OK — 88 tests, 1226 assertions, all green.**
- Docs: README v1 API section updated to the new URL and reserved-field
  list. QUESTIONS.md item resolved (decision recorded above); CONTEXT.md
  updated.
- Deviations from prompt: none; no new Composer deps.

## 2026-08-24 — Housekeeping: GitHub Actions CI
- Branch: chore/ci-workflow (off latest main).
- Added `.github/workflows/ci.yml`: job `tests` on ubuntu-latest, 2-entry
  matrix (PHP 8.1, PHP 8.2), `fail-fast: false` so both legs report
  independently. Triggers on `pull_request` and `push` to `main`. Steps:
  checkout, `shivammathur/setup-php@v2` (extensions: pdo_sqlite; coverage:
  none; XDEBUG_MODE=off), Composer package cache keyed on
  `hashFiles('composer.lock')`, `composer install --no-interaction
  --prefer-dist`, then `composer test`.
- README: added the CI status badge under the title, linking to the
  workflow's Actions page.
- No application code or test files touched, as instructed. No new
  Composer dependencies.
- Test results (local): **OK — 88 tests, 1226 assertions, all green.**
  CI itself will be exercised for real once this branch's PR is opened.
- Deviations from prompt: none. QUESTIONS.md unchanged (no blockers).

## 2026-08-24 — Increment 3: SMTP relay (PHPMailer) with retry
- Branch: feature/increment-3-mail (off latest main).
- Dependency: added phpmailer/phpmailer ^6 (v6.12.0) — the only authorised
  new dependency, runtime.
- Config: added SMTP_USER, SMTP_PASS, SMTP_ENCRYPTION (none|starttls|smtps,
  default none for Mailpit), MAIL_FROM_ADDRESS (noreply@localhost),
  MAIL_FROM_NAME (OpenSendForm), MAIL_MAX_ATTEMPTS (5),
  MAIL_RETRY_BACKOFF_MINUTES (1,5,30,120) + typed accessors (encryption
  normalised to a known value; backoff parsed to positive ints with a
  [1]-minute fallback; max attempts floored at 1). Added
  `Config::fromValues()` for explicit construction that honours an intentional
  empty string (fromEnvironment still protects defaults from blank env);
  refactored the shared dev-secret step into finalise().
- Migration 005: submissions gains attempts (INTEGER NOT NULL DEFAULT 0),
  last_attempt_at, next_attempt_at, last_error — one portable ADD COLUMN
  statement each. MigrationRunnerTest/SchemaMigrationsTest version locks
  bumped to [1,2,3,4,5] (as each migration-adding increment has done).
- `src/Mail/MessageBuilder.php` (+ `BuiltMessage`): builds subject and
  plain-text body from a form + fields and extracts Reply-To. Enforces the
  mail policy — subject/name control chars (newlines included) collapsed;
  Reply-To only a filter_var-valid `email` field (injection attempts fail and
  yield none); body values normalised, control-stripped (tab/newline kept)
  and truncated at a cap. Directly unit-tested with hostile inputs.
- `src/Mail/MailerInterface.php` (throws on failure) + `PhpMailerMailer`
  (isSMTP, host/port/timeout/encryption/auth from Config, exceptions on,
  From = service identity, CharSet UTF-8, plain text). PHPMailer's address
  validator set to `html5` so single-label hosts like `noreply@localhost`
  (the dev default) are accepted; our own boundaries validate addresses more
  strictly. `tests/Support/FakeMailer` records calls and is scriptable to
  fail (always / first-N).
- `src/Mail/DeliveryService.php`: attemptDelivery(id) loads submission+form,
  builds + sends; success → `sent` (attempts+1, last_attempt_at); failure →
  `failed` (attempts+1, truncated last_error, next_attempt_at from the
  backoff list, last value repeating past the list length) escalating to
  `dead` once attempts reach MAIL_MAX_ATTEMPTS. retryDue(now?) re-attempts
  every due `failed` row (portable lexicographic timestamp compare) and
  returns a summary. SubmissionRepository gained markSent/markFailed/markDead/
  findDueForRetry/listSummaries.
- Pipeline wiring: StoreStage is now non-terminal (returns null, stashing the
  new submission id on the context); the new terminal `DeliveryStage` makes at
  most one in-request send and ALWAYS returns success — the endpoint stays
  `{"ok":true}` on send failure (asserted). Delivery is skipped (status left
  `received`) when no mailer is wired, SMTP_HOST is empty, or nothing stored.
  AppFactory::create() takes an optional MailerInterface and builds the
  DeliveryService only when one is supplied; public/index.php wires a real
  PhpMailerMailer only when SMTP_HOST is non-empty (storage-only otherwise).
  SubmitPipelineOrderTest updated to expect the DeliveryStage terminal.
- `bin/osf`: mail:retry (runs retryDue, prints a summary, always exit 0 —
  cPanel cron), mail:test --to=ADDRESS (fixed message via configured SMTP,
  exit 0/1), submissions:list [--status=X] (id, form_key, created, status,
  attempts — never content).
- Tests added (120 total, was 88): MessageBuilder hostile-input suite (14);
  DeliveryService success/backoff/escalation/retry-due with a fake clock (7);
  pipeline integration through the app factory — ok:true + `sent` with a
  working transport, ok:true + `failed` with a failing transport, empty
  SMTP_HOST and no-mailer both leaving `received` (4); Config mail defaults/
  overrides/parsing/fromValues (7). Existing migration version-lock tests and
  the pipeline order test updated as above; no other existing test touched.
- Verification: full suite green (120 tests, 1343 assertions). Also smoke-
  tested for real against the dev Mailpit — `mail:test` delivers, and a full
  HTTP token→submit run produced status `sent` and an email in Mailpit
  (From OpenSendForm, Reply-To the submitter, subject "New form submission:
  Contact", body listing the fields).
- Docs: README gained an end-to-end local test recipe (create form, enable
  store_content, token flow, curl submit, see it in Mailpit) and the v1 API
  mail note updated. CONTEXT.md overwritten; this entry appended.
- Deviations from prompt: none functional. Two items logged to QUESTIONS.md:
  (1) delivery reads stored `content`, so with store_content OFF (default) the
  email lists no fields and retries carry none — an architecture decision for
  the owner; (2) the storage-only guard keys off an empty SMTP_HOST, which the
  env parser can't express, so it is implemented via fromValues()/the front
  controller and a dedicated MAIL_ENABLED flag is suggested for later.

## 2026-08-24 — Follow-up: mail content lifecycle + MAIL_ENABLED
- Branch: fix/mail-content-lifecycle (off latest main, PR #7 merged).
  Resolves both Increment 3 QUESTIONS.md items per the architect's rulings.
- Ruling 1 (content lifecycle): `SubmissionRepository::recordSubmission()`
  now always persists `content` as the in-flight delivery payload,
  regardless of the owning form's `store_content` toggle (previously it was
  discarded at record time when the toggle was off). Added
  `SubmissionRepository::clearContent($id)`; `DeliveryService::
  attemptDelivery()` calls it right after `markSent()` when the form's
  `store_content` is 0. Failed/dead submissions keep content untouched (for
  retry and operator recovery); the retention purge is the only other thing
  that removes it. `store_content` now means "retain content after
  successful delivery," not "store content at all" — docblocks in
  SubmissionRepository, StoreStage and DeliveryService, and the README's
  end-to-end recipe, reworded to say exactly that.
- Ruling 2 (MAIL_ENABLED): added `MAIL_ENABLED` to Config (default '1',
  env-overridable, typed `mailEnabled(): bool` accessor accepting
  1/true/yes/on case-insensitively). `DeliveryStage::shouldAttempt()` and
  `public/index.php`'s mailer wiring both now require `mailEnabled() &&
  smtpHost() !== ''` — MAIL_ENABLED is the primary switch, the SMTP_HOST
  emptiness check remains as a secondary guard (kept for the storage-only-
  via-fromValues() seam already in place).
- Tests: rewrote the store_content toggle tests in SubmissionRepositoryTest
  and SubmitEndpointTest to assert content is stored regardless of the
  toggle; added SubmissionRepositoryTest::testClearContentNullsTheColumn;
  added four DeliveryServiceTest cases (sent+store_content=0 nulls content,
  sent+store_content=1 retains, failed retains content for retry, dead
  retains content); added MailDeliveryPipelineTest::
  testMailDisabledLeavesSubmissionReceivedEvenWithSmtpConfigured; added two
  MAIL_ENABLED cases to ConfigMailTest. Full suite green (127 tests, 1364
  assertions, was 120).
- Docs: CONTEXT.md overwritten, both QUESTIONS.md Increment-3 items marked
  RESOLVED with one-line decision notes, this entry appended.
- Deviations from prompt: none. No new Composer dependencies added.

## 2026-08-24 — Increment 4: Cloudflare Turnstile (optional, per form)
- Branch: feature/increment-4-turnstile (off latest main, PR #8 merged).
  Adds optional per-form Turnstile verification. No new Composer
  dependencies — server-side verification uses PHP's curl extension directly.
- Data model: migration 006 adds `forms.turnstile_sitekey` and
  `forms.turnstile_secret` (both TEXT NULL, one portable ADD COLUMN per
  statement). A form is Turnstile-enabled iff BOTH are non-empty; there is no
  global switch. `FormRepository::setTurnstile(id, sitekey, secret)` enforces
  both-or-neither (blanks/nulls collapse to disabled) and hydrate() now
  surfaces both columns (null when unset). The secret is never returned by any
  endpoint.
- Verification seam: `src/Turnstile/` — `TurnstileResult` (PHP 8.1 enum
  VALID/INVALID/UNAVAILABLE), `TurnstileVerifierInterface`
  (`verify(secret, token, remoteIp): TurnstileResult`), `CurlTurnstileVerifier`
  (POST to challenges.cloudflare.com/turnstile/v0/siteverify; connect 2s /
  total 3s; sends remoteip only when non-empty; any curl error, non-2xx,
  non-string or malformed/`success`-less body → UNAVAILABLE), and
  `tests/Support/FakeTurnstileVerifier` (records calls, scriptable result,
  defaults VALID).
- Pipeline: new `Submit\Stages\TurnstileStage` inserted between TokenStage and
  EmailValidationStage. It returns instantly (no network) when the form is not
  Turnstile-configured; else missing `_osf_cf` → 400 `turnstile_required`,
  INVALID → 400 `turnstile_failed` (nothing stored), and VALID/UNAVAILABLE both
  proceed (fail-open). Reserved field `_osf_cf` added to SubmitContext and
  captured by FieldHygieneStage. Verifier threaded through
  `AppFactory::create` and `SubmitPipeline::create` (6th/8th optional arg,
  defaults to CurlTurnstileVerifier); SubmitPipelineOrderTest updated to lock
  the new 11-stage order.
- Token endpoint: `GET /v1/form/{key}/token` now returns
  `{"ok":true,"token":"...","turnstile":{"sitekey":"..."}}` iff the form has
  Turnstile enabled (sitekey only — the secret is never exposed).
- CLI: `bin/osf form:turnstile ID --sitekey=X --secret=Y` (enable) and
  `bin/osf form:turnstile ID --disable` (clear both), plus usage text.
- Housekeeping: added `XDEBUG_MODE: "off"` to the app service in
  .devcontainer/docker-compose.yml (with a comment; takes effect on next
  container rebuild) so bin/osf CLI runs stop emitting Xdebug connect warnings.
- Tests: added TurnstilePipelineTest (enabled+VALID → store/send; enabled+
  INVALID → turnstile_failed, nothing stored; enabled+missing token →
  turnstile_required, zero verify calls; enabled+UNAVAILABLE → fail-open
  proceeds; disabled form → zero verify calls; token endpoint includes/omits
  sitekey), CliTurnstileTest (shells out to bin/osf against a temp SQLite DB:
  enable/disable round-trip, both-keys-required guard, unknown-ID report),
  setTurnstile cases in FormRepositoryTest, and turnstile-column + version
  assertions in SchemaMigrationsTest / MigrationRunnerTest. Full suite green
  (144 tests, 1440 assertions; was 127).
- README: added a "Cloudflare Turnstile" section (where to get the free keys,
  the two CLI commands, the `_osf_cf` reserved field, the one-sentence
  fail-open policy) and folded the new stage/codes into the API section.
- Docs: CONTEXT.md overwritten, this HISTORY entry appended, QUESTIONS.md
  unchanged (nothing ambiguous this sprint).
- Deviations from prompt: none.

## 2026-08-24 — Increment 5a: admin authentication stack
- Branch: feature/increment-5a-admin-auth (off latest main).
- Scope: a WORKING but UNSTYLED server-rendered admin login. argon2id
  passwords, optional TOTP 2FA + recovery codes, per-session CSRF, hardened
  sessions with idle/absolute timeouts. No new Composer dependencies
  (authorised: none) — TOTP and base32 implemented in-repo.
- Migration 007: `admins` (email UNIQUE, display_name, password_hash,
  totp_secret NULL, totp_enabled, recovery_codes NULL, created_at,
  updated_at, last_login_at NULL). Portable types only.
- Auth primitives (`src/Auth/`): `PasswordHasher` (argon2id when
  `PASSWORD_ARGON2ID` is defined, else PASSWORD_DEFAULT; injectable algorithm;
  hash/verify/needsRehash); `Base32` (RFC 4648, unpadded encode,
  padding-agnostic/whitespace-tolerant/case-insensitive decode); `Totp`
  (RFC 6238 SHA1/6-digit/30s, +/-1 window scanned in full for constant time,
  hash_equals, otpauth:// URI, secret from random_bytes); `RecoveryCodes`
  (10×10-char, unambiguous alphabet, stored as a JSON array of
  password_hashes, single-use consume, input-tolerant).
- `AdminRepository` (prepared statements throughout): createAdmin (hashes),
  findByEmail (case-insensitive)/findById, recordLogin, updatePassword/
  updatePasswordHash, setTotp/enableTotp/disableTotp, setRecoveryCodes,
  consumeRecoveryCode.
- Session seam: `SessionInterface` (get/set/remove/regenerate/destroy),
  `NativeSession` (lazy start so the public API emits no cookie; HttpOnly,
  SameSite=Strict, Secure on HTTPS; regenerate/destroy) — the only place
  $_SESSION is touched — and `tests/Support/FakeSession` (array-backed,
  counts regenerate/destroy). `Csrf` (per-session token, hash_equals,
  fail-closed).
- `AuthService`: attemptLogin → `LoginOutcome` enum (RateLimited/Invalid/
  NeedsTotp/Success), rate-limited per-IP AND per-email via the existing
  RateLimiter; unknown email still verifies against a lazy dummy hash and
  returns the SAME Invalid outcome as a wrong password (no enumeration);
  opportunistic rehash on login; session id regenerated on every privilege
  change; idle (30 min) and absolute (12 h) timeouts enforced in
  currentAdmin(); verifyTotp() accepts a TOTP or a single-use recovery code.
- Admin HTTP (`src/Admin/`): `AdminRoutes` + `AdminController`, plain-PHP
  templates in `templates/admin/` rendered by `TemplateRenderer` with an
  `h()` escaper. Routes: GET/POST /admin/login, GET/POST /admin/totp
  (recovery-code path included), POST /admin/logout, GET /admin (placeholder
  dashboard: signed-in name + logout, nothing else), plus the enrolment flow
  GET/POST /admin/totp/setup and POST /admin/totp/recovery-codes/regenerate
  (regeneration gated on a current TOTP code; recovery codes shown ONCE with a
  stern copy-them-now note). `SecurityHeadersMiddleware` (X-Frame-Options
  DENY, X-Content-Type-Options nosniff, Referrer-Policy no-referrer,
  Cache-Control no-store) wraps the whole /admin group; `AuthMiddleware`
  protects everything except the login/totp routes.
- Wiring: `AppFactory::create` gains a 7th optional arg `?SessionInterface`
  (defaults to NativeSession; tests inject FakeSession) and registers the auth
  stack in the container, then calls `AdminRoutes::register`. public/index.php
  unchanged (session defaults).
- CLI: `bin/osf admin:create --email= --name=` prompts for the password
  interactively (hidden via `stty` on a TTY, visible fallback with a warning),
  refuses passwords < 12 chars, prints nothing sensitive; usage text updated.
- Tests (+86, 144 → 230; 1721 assertions): RFC 6238 SHA1 vectors + window
  (TotpTest); RFC 4648 vectors + random round-trips + tolerant decode
  (Base32Test); PasswordHasher verify/forced-bcrypt-fallback/needsRehash;
  RecoveryCodes single-use + tolerance; AuthService full outcome matrix with
  FakeSession + FixedClock (rate limiting after N failures, dummy-hash timing
  path executed for unknown email via a spy hasher, session regenerated on
  login, TOTP gate only when enabled, recovery-code single-use, idle/absolute
  timeouts); Csrf reject on missing/wrong/never-issued token; HTTP-level
  AdminHttpTest through the app factory (login renders, wrong creds → generic
  401, good creds+TOTP off → dashboard, good creds+TOTP on → totp → dashboard,
  protected route redirects logged-out, security headers present, logout
  destroys session, CSRF reject missing/wrong); CliAdminTest (shells out:
  create, weak-password refusal, mismatch, duplicate email); admins-table +
  version 7 assertions in Schema/MigrationRunner tests. Full suite green.
- README: added an "Admin panel" section (create an admin via CLI, browse to
  /admin/login, TOTP + recovery-code note, and a note that styling arrives in
  the next increment).
- Docs: CONTEXT.md overwritten, this HISTORY entry appended, QUESTIONS.md
  unchanged (nothing ambiguous this sprint).
- Deviations from prompt: none. Escaping helper is provided as a real function
  `OpenSendForm\Admin\h()` (used as `h()` in templates via `use function`),
  matching the prompt's `h()` call site.

## 2026-08-24 — fix/5a-hardening: TOTP rate limit, pending expiry, session strict mode
- Branch: fix/5a-hardening (off latest main). Follow-up patch implementing
  four architect rulings from code review of Increment 5a. No new Composer
  dependencies (authorised: none).
- Ruling 1 — `AuthService::verifyTotp()` now takes a `$ip` parameter and
  returns a new `TotpOutcome` enum (RateLimited/Invalid/Success) instead of
  bool. Before any code/recovery-code verification it hits two RateLimiter
  buckets — `admintotp:admin:<id>` (max 5) and `admintotp:ip:<ip>` (max 10),
  both over the existing 900s window — mirroring the login limiter's
  both-buckets-must-pass shape. `AdminController::totp()` passes the
  request's remote IP and, on `RateLimited`, renders the totp view with
  "Too many attempts. Please try again later." at HTTP 429.
- Ruling 2 — parking `SESSION_PENDING_TOTP` now also stamps
  `SESSION_PENDING_TOTP_AT` with the current clock time.
  `pendingTotpAdminId()` clears both keys and returns null once more than
  300s have elapsed, so a stale password-verified step can't be replayed
  indefinitely; `verifyTotp()` and `totpForm()`/`totp()` fall through to
  their existing "no pending login" handling (redirect to /admin/login),
  so no controller branching was needed beyond passing `$ip` through.
- Ruling 3 — `NativeSession::ensureStarted()` sets
  `ini_set('session.use_strict_mode', '1')` before `session_start()`
  (skipped when a session is already active, as before), with a comment on
  why: refuses attacker-supplied session ids (session fixation).
- Ruling 4 — added `AdminHttpTest::testLoginEscapesSubmittedEmailInErrorResponse`:
  POSTs `"/><script>alert(1)</script>` as the email with a wrong password
  and asserts the raw `<script>alert(1)</script>` sequence is absent while
  its `htmlspecialchars`-escaped form is present. Passed without further
  code changes — the login template already re-renders `email` through the
  `h()` escaper — so this is a regression lock, not a fix.
- Tests (+9, 239 total; 1759 assertions): AuthServiceTest gained TOTP
  rate-limit coverage (per-admin cap, per-IP cap, window rollover via
  FixedClock at a shortened 60s window kept inside the 300s pending TTL so
  the two mechanisms don't confound each other) and pending-TOTP-expiry
  coverage (fake clock past 300s ⇒ `pendingTotpAdminId()` null and
  `verifyTotp()` treats it as no pending login; just-under-300s stays
  alive). AdminHttpTest gained HTTP-level coverage for the 429 outcome
  (5 wrong attempts then a 429 with the generic message) and the expired
  -pending redirect, plus the XSS regression test above. Existing
  `verifyTotp()` call sites (AuthServiceTest, AdminController) updated for
  the new `(code, ip): TotpOutcome` signature. Full suite green.
- Docs: CONTEXT.md updated (Admin authentication section describes the
  hardening; "What exists now" notes the follow-up patch and its new file
  `src/Auth/TotpOutcome.php`), this HISTORY entry appended. QUESTIONS.md
  unchanged — the architect's rulings for this patch were prescriptive, not
  open questions, so nothing new was logged there.
- Deviations from prompt: none.

## 2026-08-24 — Increment 5b: admin screens + design system
- Branch: feature/increment-5b-admin-ui (off latest main, after PR #11).
- Vendored assets (authorised, both MIT, licence headers + pinned versions
  in-file and in CONTEXT): Pico.css v2.0.6 → `public/assets/vendor/
  pico.min.css`; qrcode-generator by Kazuhiko Arase v1.4.4 →
  `public/assets/vendor/qrcode.js`. Fetched from npm at those pinned
  releases; no CDN references at runtime. No Composer dependencies added.
- Design system: admin layout now loads Pico + one overrides file
  (`public/assets/admin.css`) defining a brand palette via CSS custom
  properties for BOTH light and dark schemes (auto via prefers-color-scheme
  plus a manual toggle). Pre-paint `theme.js` (blocking, in <head>) applies
  the stored choice before first paint and wires the nav toggle;
  `admin.js` (deferred) holds all other progressive enhancements. A top-nav
  partial (`_nav.php`: Dashboard/Forms/Submissions + theme toggle + admin
  name + CSRF logout) and a flash partial (`_flash.php`) were added, backed
  by a `Flash` session helper and an `AdminView` chrome renderer. Strict
  `Content-Security-Policy` (`default-src 'self'; script-src 'self';
  style-src 'self'; img-src 'self' data:; frame-ancestors 'none'`) added to
  SecurityHeadersMiddleware — admin only; the public JSON API stays CSP-free.
  Every screen works with JS disabled; no inline scripts/styles/handlers.
- Dashboard (`AdminController::dashboard`): counts of active forms,
  submissions today (cutoff from the injected clock), and failed/dead
  totals, plus the 10 most recent failed/dead submissions (metadata only)
  linking to the filtered submissions view.
- Forms screens (`FormsController` + `forms_list.php`/`form_edit.php`):
  list (key click-to-copy, recipient, active + Turnstile badges),
  create/edit (origins textarea round-tripped to the JSON array,
  store_content explained inline, retention_days [validated 1–3650],
  is_active, Turnstile sitekey+secret with both-or-neither enforcement).
  The secret is write-only — never echoed; a set/not-set hint is shown and
  a blank secret on edit keeps the stored one, clearing the sitekey
  disables Turnstile. Validation failures re-render inline (422) with input
  preserved (never the secret). Enable/disable from the list.
- Submissions screen (`SubmissionsController` + `submissions.php`):
  paginated 50/page (id, form, created, status, attempts, truncated last
  error), filter by status and form; per-row Retry (POST) and bulk "Retry
  all due now" both drive DeliveryService (guarded when no mailer is wired)
  and redirect back to the same filtered view. No stored content is ever
  displayed — WHY noted in a template comment (in-flight payload / privacy).
- TOTP UX (the three architect-logged items): (a) setup renders the
  otpauth URI as an SVG QR client-side from a data-attribute via the
  vendored qrcode.js, manual key + URI kept as the no-JS fallback, secret
  never leaves the page; (b) login and setup code fields upgrade to six
  auto-advancing/paste-aware/auto-submitting one-digit boxes built from the
  plain input (degrade to one field with JS off); (c) recovery-codes screen
  gained copy-all, download-as-.txt (Blob URL) and a "saved" checkbox
  gating the continue link, codes still plainly listed without JS.
- Repository additions (no controller-side validation): FormRepository
  `updateForm`, extended `createForm` (store_content/retention/active),
  retention-range validation, `countActive`; SubmissionRepository
  `countSince`/`countByStatus`/`recentByStatuses` and
  `listPage`/`countFiltered` (portable status+form filter, all
  metadata-only, content never selected). `AppFactory` registers `Flash`.
- Tests (+26, 265 total; 1912 assertions): `tests/Admin/AdminUiTest.php`
  covers dashboard counts, forms list/create/edit happy paths + validation
  failures (bad email, bad origin, Turnstile one-key-only) with input
  preserved, enable/disable (incl. CSRF reject), the write-only secret
  (never echoed, kept on blank edit, cleared with the sitekey), submissions
  pagination + status/form filters, content-never-shown, per-row retry
  (success + repeat-failure via the scriptable FakeMailer) and bulk
  retry-due, CSP present on admin / absent on the public API, vendored +
  enhancement assets existing and referenced by the layout, and templates
  carrying no inline handlers or inline scripts. Full suite green.
- Docs: CONTEXT.md overwritten (new Admin UI section, vendored-asset
  pins), this HISTORY entry appended, README Admin section expanded
  (screens overview, theming note, CLI+UI provisioning). QUESTIONS.md
  unchanged — all prior items resolved; no new blockers (the retention
  bounds and the "keep secret on blank edit" convenience were in-scope
  UX decisions, not architecture questions).
- Deviations from prompt: none.

## 2026-08-24 — Follow-up patch: 5b UX defects (field testing)
- Branch: fix/5b-ux-defects (off main, after PR #12).
- Defect 1 — invalid TOTP code showed no visible error after the
  segmented-box auto-submit re-render. Diagnosis: the error line was already
  present in the HTML (`<p role="alert">`) but carried no styling, so it
  blended into the page — inconsistent with `totp_setup.php` and
  `recovery_codes.php`, which already used the `osf-flash osf-flash--error`
  class for the same kind of message. Fix: `templates/admin/totp.php` now
  reuses that existing class (no new CSS). Confirmed the segmented-box
  enhancement already re-initialises correctly on the re-render (empty
  boxes, focus on box 1) — it is a fresh full-page load each time, not an
  in-place DOM patch, so `admin.js`'s `buildBoxes()` runs from scratch; the
  server never echoes the failed code back into the input, so nothing to
  reset. No JS change was needed for this half of the defect, only the
  regression test.
- Defect 2 — recovery codes (10-char alphanumeric) couldn't be typed into
  the 6-digit segmented boxes with JS on. Ruling implemented: consolidated
  `totp.php` from two forms (a boxed `code` field plus a separate unboxed
  "Lost your device?" `code` field) to ONE form with ONE `name="code"`
  field, with the `pattern="[0-9]*"`/`maxlength="6"`/`inputmode="numeric"`
  restrictions removed so the bare field accepts either code type — this is
  the "single plain input serves both cases" no-JS fallback the ruling
  describes. `admin.js`'s `buildBoxes()` gained an opt-in
  `data-totp-recovery-toggle` attribute (set only on this field, not on
  `totp_setup.php`'s enrolment-confirmation field, where a recovery code
  makes no sense): when present, a "Use a recovery code instead" link
  toggles the same input between hidden-carrier-behind-six-boxes and a
  plain visible text field (clearing values both ways, relabelling, no
  auto-submit in text mode since box-only listeners never touch the plain
  input), with a "Use authenticator code instead" link back. New
  `.osf-link-button` style in `admin.css`.
- Defect 3 — forms list page reported as rendering unstyled. Investigation:
  built and ran the app end-to-end (PHP built-in server, real admin +
  form), diffed the raw HTTP response against the dashboard, and found no
  bypass — `FormsController::index()` already goes through the same
  `AdminView::renderPage()` → `TemplateRenderer::render()` → `layout.php`
  path as every other screen, with identical `pico.min.css`/`admin.css`
  `<link>` tags, both assets serving 200 with the correct content type.
  Could not reproduce a code-level defect (recorded here rather than
  QUESTIONS.md since it isn't blocking — the required regression test
  closes the gap regardless of root cause, which may have been a one-off
  browser-cache artefact during the original field test). Added
  `AdminUiTest::testEveryAdminScreenReferencesPicoCss()`, looping over
  dashboard/forms-list/form-create/form-edit/submissions/totp-setup and
  asserting each 200 response body references `/assets/vendor/pico.min.css`,
  so a single unstyled admin page can never ship unnoticed again.
- Tests (+3, 268 total; 1939 assertions): `AdminHttpTest` gained
  `testInvalidTotpCodeShowsAlertRegion` (401 body contains the exact styled
  `role="alert"` markup with "Invalid code."; the carrier input has no
  leftover `value="000000"`) and
  `testTotpRecoveryCodeFallbackMarkupAndSubmission` (GET body has
  `data-totp-recovery-toggle` and no `maxlength="6"`/`pattern="[0-9]*"`;
  POSTing a real recovery code through the single `code` field returns
  302 → `/admin`). `AdminUiTest` gained the pico.min.css route-loop above.
  Full suite green. Also manually verified live against the PHP built-in
  server: enrolled TOTP, hit `/admin/totp` with a wrong code (saw the
  styled alert), then signed in with a recovery code through the same
  field (302 to `/admin`).
- Docs: CONTEXT.md updated (TOTP UX + forms-list sections), this HISTORY
  entry appended. QUESTIONS.md unchanged — defect 3's non-reproduction is
  noted above, not a blocking open question.
- Deviations from prompt: none.

## 2026-08-25 — Increment 5c: admin account management + 2FA lifecycle UX
- Branch: feature/increment-5c-account (from main @ 767b963).
- Single-tenant admin model made explicit and documented: all admins are
  co-operators of one installation and see all forms/submissions; no roles,
  no per-form ownership; no account deletion — retirement is deactivation.
- Migration 008 (`008_admins_is_active.sql`) adds
  `admins.is_active INTEGER NOT NULL DEFAULT 1`. `AdminRepository` hydrates
  `is_active` and gains `listAll/countActive/setActive/updateDisplayName/
  updateEmail`. `AuthService`: after a correct password an inactive admin is
  refused with the SAME generic `Invalid` outcome (no status disclosure), and
  `currentAdmin()` invalidates a live session whose admin has since been
  deactivated (or deleted) on its very next request.
- Account screen: `Admin\AccountController` + `templates/admin/account.php`.
  Change display name (CSRF only); change email (current password required +
  format validation + uniqueness against other admins; normalised to lower
  case); change password (current password + new ≥ 12 chars entered twice;
  session id regenerated on success, mirroring login's privilege-change
  rotation). `_nav.php` now renders the admin's name as an "Account" link and
  gained an "Admins" nav item.
- Admins screen: `Admin\AdminsController` + `templates/admin/admins.php`.
  Roster (email, name, 2FA on/off badge, active badge, last login); create an
  admin with an initial password ≥ 12 (inline guidance to change it after
  first sign-in); deactivate/reactivate. GUARD (server-enforced AND button
  hidden): the last remaining ACTIVE admin can never be deactivated, including
  self-deactivation when last active. No delete action.
- 2FA nudge: `AdminController::dashboard` computes `showNudge` (TOTP off AND
  not dismissed this session); `dismissNudge` (CSRF POST) sets a session flag.
  Dismissible-per-session banner in `dashboard.php`; absent when 2FA on.
- 2FA disable: `AdminController::disableTotp` on the enabled `totp_setup.php`
  view — requires current password AND a current TOTP code; on success clears
  `totp_secret`/`totp_enabled`/`recovery_codes`, flashes, and re-arms the
  dashboard nudge (removes the dismiss flag). New `/admin/totp/disable` route,
  separated `.osf-danger-zone` section.
- Recovery/2FA UX consistency: login `totp.php` states "Enter ONE of your
  recovery codes" and hints at the 10-character format; `recovery_codes.php`
  lists codes one per line in a monospace `.osf-recovery-block` `<pre>`
  (copy-all still preserves line breaks) and says each works exactly once; the
  regenerate-recovery-codes confirm input now uses the same `data-totp-code`
  six-box enhancement as the login screen (shared admin.js, no divergent
  markup) — no JS change was needed.
- Routes: `/admin/account` (+ `/name`,`/email`,`/password` POSTs),
  `/admin/admins` (+ `/{id}/deactivate`,`/{id}/reactivate`, create POST),
  `/admin/totp/disable`, `/admin/nudge/dismiss` — all behind AuthMiddleware.
- Tests (+28, 296 total; 2069 assertions): new
  `tests/Admin/AccountAdminsHttpTest.php` covers account name/email/password
  changes incl. wrong-current-password rejections, mismatch/too-short, email
  uniqueness + normalisation, and session regeneration on password change;
  admins list/create (incl. short-password 422 and duplicate 422) /
  deactivate / reactivate; the last-active guard (button hidden AND server
  refusal) incl. self-deactivation, plus the allowed self-deactivation when
  not last (session then invalidated); inactive-admin login refused
  generically AND a live session invalidated when the admin is deactivated;
  nudge present/dismiss/absent-when-enabled; the 2FA disable matrix (wrong
  password / wrong code / success + state cleanup + nudge re-arm); and the
  recovery-screen copy. `SchemaMigrationsTest`/`MigrationRunnerTest` version
  lists bumped to include 8 and the admins-columns check gained `is_active`;
  `AdminUiTest`'s pico.min.css route-loop gained the account + admins screens.
  Full suite green.
- Docs: README "Admin model" subsection added (single shared workspace, all
  admins see all forms, how to add/deactivate admins, first admin from the
  installer or CLI) plus Account/Admins screens and the 2FA nudge/disable/
  regenerate notes; CONTEXT.md overwritten; this HISTORY entry appended.
  QUESTIONS.md unchanged — nothing ambiguous this sprint.
- Deviations from prompt: none.

## 2026-08-25 — Increment 6a: browser installer, part one (engine)
- Branch: feature/increment-6a-installer (from main @ 125e89a).
- Config refactor (`src/Config.php`): added `fromFile(path)` (reads a PHP array
  file over defaults; trusted, so explicit empties are honoured) and the merged
  `load(?file, ?env)` factory with precedence defaults < file < environment —
  env always wins, so the dev container is unchanged and a fileless boot equals
  `fromEnvironment()`. Added `generateSecret()` (bin2hex of 32 random bytes → 64
  hex), `defaultConfigFilePath()`, new `DB_USER`/`DB_PASS` keys with
  `dbUser()`/`dbPass()` accessors (null when empty). `public/index.php` and
  `bin/osf` now boot via `Config::load($paths->configPath)` and pass DB creds to
  `Database::connect`. Existing Config tests unchanged; new `ConfigMergeTest`
  covers file<env precedence, the fileless path, blank-env non-clobber, secret
  format and the credential accessors.
- Installed-state model: installed iff BOTH `var/config.php` and
  `var/install.lock` exist (`Install\Paths::isInstalled()`, one definition).
  `Install\InstallStateMiddleware` (app-level, outermost): not installed → every
  non-install route (public API included) redirects to /install; installed → all
  /install routes 404, except /install/done which stays reachable and
  self-guards on a one-time session flag. Gating is OPT-IN: enabled only when
  install `Paths` are passed to `AppFactory::create` (new 8th param), so the
  existing suite — which passes none — behaves as installed and is untouched.
  `public/index.php` passes `Paths::production()`.
- Requirements (`Install\Requirements` + injectable `EnvironmentProbe`, real
  `SystemProbe`): pass/warn/fail rows each with plain-language remedy. PHP≥8.1
  (fail), pdo_sqlite (fail only when pdo_mysql also absent, else warn), pdo_mysql
  (warn), openssl (fail), curl (warn — Turnstile note), var/ + var/data/ writable
  (fail), HTTPS (warn, request-aware incl. X-Forwarded-Proto). `hasFailures()`
  blocks Continue.
- Installer service (`Install\InstallerService`; `DbConnector` seam +
  `PdoDbConnector`): `prepareDatabase` (sqlite default file under var/data with a
  writability check; mysql host/port/dbname/user/pass → DSN), `connect` (live
  test; friendly `InstallerException`, never a raw driver string), `migrate`,
  `createAdmin` (via `AdminRepository`, ≥12 twice, friendly errors), and `commit`
  — write `var/config.php` then `var/install.lock` atomically (temp+rename,
  chmod 0600); a lock-write failure unlinks the just-written config so no partial
  install remains. Written config: APP_ENV=production, generated APP_SECRET,
  DB_DSN/DB_USER/DB_PASS, MAIL_ENABLED=0 (email is set up later, in 6b); header
  documents the env-override precedence. Lock holds installed_at + version.
- Wizard (`Install\InstallController`/`InstallRoutes`, `templates/install/{layout,
  welcome,database,admin,finish,done}.php`; reuses the admin design system + the
  strict CSP via the shared `SecurityHeadersMiddleware`): GET /install (welcome +
  requirements table, Continue disabled on any fail), GET/POST /install/database
  (choice + creds + live test), GET/POST /install/admin (first admin), GET/POST
  /install/finish (review + atomic commit), GET /install/done (success: sign-in
  link, delete-the-lock-to-reinstall note, email-next reminder). Step order held
  in the session; jumping ahead bounces to the earliest incomplete step; every
  POST CSRF-protected; the DB and admin passwords are never echoed back into a
  field. Each step also HARD-refuses to run once installed (not just routing).
- CLI: `bin/osf install:status` prints installed/not-installed, config/lock
  presence and (when present) the lock's timestamp/version — no DB connection,
  no secrets. `OSF_BASE_DIR` relocates the writable var/ tree; `Paths::production`,
  the front controller and the CLI all honour it.
- Tests (+40, 336 total; 2244 assertions): `tests/Install/RequirementsTest`
  (every pass/warn/fail branch + remedy present via `FakeProbe`),
  `InstallerServiceTest` (mysql fake-connector success + friendly failure,
  config/lock contents incl. 64-hex secret + MAIL_ENABLED=0, unique secrets,
  commit atomicity on induced lock failure), `InstallerHttpTest` (full happy-path
  wizard into a temp SQLite dir then admin login; step-order enforcement; bad
  CSRF; password-never-echoed; not-installed redirects incl. public API;
  installed→404; CSP + pico + no-inline templates), `WelcomeTemplateTest`
  (Continue disabled on fail), `tests/ConfigMergeTest`, `tests/Cli/
  CliInstallStatusTest` (both states via OSF_BASE_DIR); support `FakeProbe`,
  `FakeDbConnector`. Full suite green.
- Docs: README gained an end-user "Installing" section (upload zip, visit
  /install, follow steps, email after first login, delete the lock to re-run)
  with the developer section intact; the admin-model note updated (first admin
  now from the installer). CONTEXT.md overwritten and trimmed to ~150 lines
  (Mail/Turnstile condensed, 6a section added). This HISTORY entry appended.
- QUESTIONS.md: no new questions; Increment 3 item 2 explicitly anticipated this
  installer increment (MAIL_ENABLED flag), now the installer writes MAIL_ENABLED=0.
- Deviations from prompt: added a GET /install/finish review page (the prompt
  listed only POST /install/finish) so the commit is an explicit confirmation
  step rather than an implicit redirect; and an OSF_BASE_DIR override on
  `Paths::production()` to keep install:status testable and allow relocating
  var/. Neither changes public API behaviour.
