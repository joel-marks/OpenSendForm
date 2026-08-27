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

## 2026-08-25 — Increment 6b: mail-setup wizard (SMTP + deliverability) + polish
- Branch: feature/increment-6b-mail-wizard, based on
  feature/increment-6a-installer (NOT main). 6a is not yet merged to main, and
  6b depends on its atomic config writer, `Config::load/fromFile`, DB_USER/PASS
  and the installer done screen; branching from main would have dropped all of
  that and broken task 4. Logged to QUESTIONS.md (Increment 6b item 1) as a
  merge-order flag. No new Composer dependencies (authorised: none).
- DNS seam: `Mail\DnsResolver` (TXT-only interface), `Mail\SystemDnsResolver`
  (dns_get_record, `@`-suppressed so an offline host yields [] not an error),
  and `tests/Support/FakeDnsResolver` (scriptable, never touches the network).
- `Mail\DeliverabilityChecker` (pure; over the resolver): checks the From
  address's domain for SPF (TXT `^v=spf1`), DKIM (`{selector}._domainkey`,
  matched on `v=DKIM1`/`p=`) and DMARC (`_dmarc` `^v=DMARC1`). Each result is a
  stable array (state, ok/amber, found record, recommended copy-paste record,
  one-line explain, where-to-add). A TXT that exists but lacks the version tag
  reads as absent (the "malformed" case). Recommendations: SPF seeds an
  `include:` from the SMTP host's registrable domain (last two labels; omitted
  for localhost/IP/single-label); DMARC seeds `rua=mailto:` the admin; DKIM has
  no synthesizable value (the host supplies the key) so only the record name is
  shown. `domainOf()` is a public static helper (used by the CLI too).
- Config write-back: `Install\ConfigWriter` reads the current var/config.php
  array, merges the changed keys over it (untouched keys — APP_SECRET, DB_* —
  preserved) and writes atomically (temp file in the same dir, chmod 0600,
  rename). `currentFileValues()`/`fileValue()` expose the stored file layer for
  the keep-existing-password rule and env-shadow detection. Env still overrides
  the file at load (Config::load precedence unchanged, re-asserted by a test).
- `Admin\MailController` + `templates/admin/mail.php` at `/admin/mail` (nav
  "Email"): (1) settings form (host/port/encryption[none/starttls/smtps with a
  plain line each]/user/password + From address/name + MAIL_ENABLED), validated
  (valid From email, non-empty From name, port 1–65535, host required to enable)
  and re-rendered inline on failure with values preserved (never the password).
  Password is WRITE-ONLY: blank keeps the stored FILE value; the stored secret is
  never rendered (a set/not-set hint shows). An env-shadow notice names any
  setting whose stored value the environment is overriding. (2) Test send
  (POST /mail/test) via the container `MailerInterface` on the SAVED config,
  recipient defaulting to the logged-in admin; success/failure flashed, the
  error sanitised (control chars collapsed) and truncated to 300 chars; a
  success while sending is off unlocks a one-click "Enable sending now"
  (POST /mail/enable → MAIL_ENABLED=1). (3) Deliverability section, keyed on the
  From domain, with a re-checkable DKIM selector (GET form), green/amber states,
  the exact record + a copy button (reusing the shared `[data-copy]`) and one
  sentence on where to add it.
- Wiring: AppFactory gains a 9th `?DnsResolver` param (defaults SystemDnsResolver)
  and registers `MailerInterface` (the injected fake, else a PhpMailerMailer over
  current Config — always available so a test can be sent while sending is off),
  `Install\ConfigWriter` (on `Paths::configPath`) and `DeliverabilityChecker`.
  AdminRoutes adds the four mail routes and `/nudge/mail/dismiss`.
- Handoff: installer `done.php` links `/admin/mail`; the dashboard shows a
  second dismissible nudge "Email sending is not set up yet" (mirrors the 2FA
  nudge) whenever MAIL_ENABLED is off, via `AdminController::dismissMailNudge`
  and a per-session flag.
- Installer polish (field-test finding): `/install/database`'s MySQL section is
  now `<section data-mysql-details>` (still visible with JS off), hidden by the
  new `public/assets/install.js` while the SQLite radio (`data-db-driver`) is
  selected and shown for MySQL; the install layout loads the script.
- CLI: `bin/osf mail:status` prints the mail config state (sending on/off, host
  set?, port, encryption, auth present?, From address — never a secret) then runs
  live SPF/DKIM/DMARC lookups for the From domain (labelled live; skips when the
  domain is unusable or the resolver is offline).
- Tests (+45, 381 total; 2401 assertions): `DeliverabilityCheckerTest`
  (present/absent/malformed for each record, recommendation text, selector
  normalisation, invalid From); `ConfigWriterTest` (merge/preserve, load-back,
  keep-password, env-still-wins, unwritable-dir); `MailWizardHttpTest` (save
  round-trip, keep/replace password, validation, password-never-rendered,
  env-shadow notice, test-send success/default-recipient/failure/invalid,
  enable-now, deliverability states + selector re-check + invalid-From prompt,
  dashboard banner shown/dismiss/absent-when-enabled, nav link);
  `InstallerDatabaseToggleTest` (toggle hooks present, no-JS visible, JS + layout
  wiring); `CliMailStatusTest` (config-state output, graceful domain skip, no
  secrets). `InstallerHttpTest` done-screen gains a `/admin/mail` link assertion.
  Full suite green. Also smoke-tested for real: `mail:status` (live DNS) and a
  `mail:test` delivering to the dev Mailpit.
- Docs: README gained a "Setting up email" end-user section (the three parts,
  why SPF/DKIM/DMARC matter in three sentences, the test email as proof) and an
  Email nav entry; the Installing note dropped the "wizard lands later" caveat.
  CONTEXT.md overwritten (6b section; ~150 lines), this HISTORY entry appended,
  QUESTIONS.md gained the branch/merge-order flag.
- Deviations from prompt: branch based on 6a rather than main (necessary; see
  above and QUESTIONS.md). No functional deviations otherwise.

## 2026-08-26 — Increment 7: embed snippet + submission JS
- Branch: feature/increment-7-embed (off main).
- The client artefact: `public/embed/osf.js` — one static, dependency-free
  vanilla-ES2017 file, no build step. Per form (`form[data-osf-key]`,
  initialised independently): fetches a token from
  `{data-osf-url}/v1/form/{key}/token` and holds it; injects a visually-hidden
  `_osf_hp` honeypot; submits over `fetch` (urlencoded, `Accept:
  application/json`) with `_osf_token`/`_osf_hp`/`_osf_cf`. Rich UX: submitting
  spinner + disabled button; success = focus-trapped overlay dialog ("Message
  sent" + OK/Esc) covering the wrapped form area, then a submitted panel with
  "Send another" that resets the form and re-fetches a token; failure = inline
  field error for `invalid_email`/`email_domain_invalid` (attached to
  `[name=email]`, value preserved) else a form-level strip; `token_expired`
  handled invisibly (refresh token, retry once, only then surface); Turnstile
  loaded once and rendered above the submit when the token response carries a
  sitekey, reset on `turnstile_failed`/`turnstile_required`. Namespaced injected
  `<style>` (`.osf-` prefix), CSS-variable themable, `prefers-reduced-motion`,
  aria-live announcements. Events `osf:submit`/`osf:success`/`osf:error` on the
  form; `data-osf-ui="none"` suppresses UI but keeps events + wire handling.
  Every unexpected path degrades to the native POST; the file never throws
  uncaught.
- Server additions (minimal): `src/Http/SubmitHtmlPage.php` renders the no-JS
  success/error page (self-contained inline styles, back-link from a validated
  http(s) Referer only). `Routes::submit` now content-negotiates — a client that
  prefers `text/html` and does not ask for JSON gets the HTML page; the frozen
  JSON contract is unchanged for `fetch`/API callers (Accept empty or containing
  `application/json` → JSON). New routes: `GET /embed/osf.js` (served with
  `Cache-Control: public, max-age=31536000, immutable`; the front-controller
  fallback/header seam — Apache serves the static file directly in prod) and
  `GET /embed/manual.html` (the manual checklist, dev-only, 404 elsewhere).
- Admin: form-edit gains an **Embed code** panel (existing forms only) with the
  ready-filled copy-paste snippet (form action = submit URL for the no-JS
  fallback, `data-osf-key`/`data-osf-url`, and the `?v=`-versioned script tag),
  reusing the `[data-copy]` button. `FormsController` derives the installation
  base URL from the request; empty host → no panel (so path-only test requests
  and the new-form screen show nothing).
- Tests (+14, 395 total, 2445 assertions): `SubmitHtmlResponseTest` (HTML
  success stores + renders, HTML error message + safe back-link, non-http
  Referer rejected, well-formed page, and three JSON-contract regressions:
  no-Accept, Accept application/json, and json-preferred-over-html);
  `EmbedAssetTest` (cache headers + body markers, size ceiling, `node --check`
  parse gate skipped if node absent, manual page dev-only 200 vs 404);
  `EmbedPanelTest` (snippet key/URL/version present on edit, absent on new).
  Full suite green. Also smoke-tested the running dev server: text/html POST →
  HTML "Message sent" page, application/json POST → JSON, `/embed/osf.js` served.
  `tests/embed-manual.html` added for human-driven state checks against the live
  dev API (Mailpit for delivery).
- Docs: README gained an "Embedding a form" end-user section (two-paste snippet,
  no-JS guarantee sentence, theming-variable table, events, `data-osf-ui`,
  manual page) and a content-negotiation note on the submit endpoint.
- Deviations from prompt:
  1. `osf.js` is 15.4 KB unminified, over the brief's 12 KB target. The locked
     rich-UX feature set does not fit 12 KB as readable/unminified source (even
     fully de-indented/comment-stripped it is ~12.5 KB); "no build step" +
     "unminified" preclude minifying. Kept readable (2-space indent to trim),
     size-guarded at 16 KB against regression. Flagged in QUESTIONS.md #2 for
     ratification.
  2. No-JS submissions are accepted-and-silently-discarded (no email) because
     the LOCKED token stage treats a missing token as a bot signal; not changed
     (out of scope + security). The no-JS HTML rendering ships; delivery of no-JS
     posts awaits an architect ruling — QUESTIONS.md #1.
  3. `osf.js` uses 2-space indentation (repo default is 4) purely to reduce the
     shipped asset's size.

## 2026-08-26 — Sprint: no-JS submission policy (fix/nojs-policy)
- Branch: fix/nojs-policy (off latest main, PRs #15–#17 merged).
- Follow-up patch resolving both Increment 7 QUESTIONS.md items per explicit
  architect rulings.
- Migration 009: `forms.allow_nojs INTEGER NOT NULL DEFAULT 0`. Threaded
  through `FormRepository::createForm/updateForm` (new optional trailing
  param, default off — every existing caller unaffected) and `hydrate()`.
- `SubmitContext` gained `prefersHtml` (computed once from the Accept header
  in the constructor) so pipeline stages can see the same content-negotiation
  decision `Routes::submit` uses to pick JSON vs HTML rendering; `Routes`
  now reuses `$context->prefersHtml` instead of recomputing it.
- `TokenStage` no-JS policy (JSON/fetch path untouched in every branch):
  on the HTML-negotiated path, `allow_nojs = 0` (default) turns a
  missing/forged/too-young token into an honest `400 javascript_required`
  error ("This form requires JavaScript to submit. Your message was not
  sent.") and nothing is stored; `allow_nojs = 1` skips the check only for a
  *missing* token (proceeds to store + deliver), while a present-but-invalid
  token on that same path still falls through to the ordinary silent
  discard. `HoneypotStage` behaviour needed no code change (its unconditional
  success outcome already renders the generic HTML success page, not the
  honest error) — documented why in both stage docblocks.
- Honeypot hardening: the admin embed-code panel's snippet now ships `_osf_hp`
  as a static hidden `<input>` (`display:none`, `aria-hidden="true"`,
  `tabindex="-1"`) so it protects no-JS posts; `osf.js` reuses an existing
  `[name="_osf_hp"]` field instead of injecting a duplicate (file stays at
  15,642 bytes, under the 16 KB guard).
- Admin form-edit: "Allow submissions without JavaScript" checkbox with an
  inline trade-off sentence (reduced bot protection; Turnstile-enabled forms
  can't be submitted without JavaScript either way). `bin/osf form:list` now
  shows an `[allow_nojs]` tag.
- README: replaced the single no-JS guarantee sentence with a two-mode
  description (off = honest error/nothing sent; on = delivered with the
  min-time check waived) and a note on the new `javascript_required` error
  code as the one HTML-only exception to "bot checks fail silently".
- Tests (+11, 404 total, 2483 assertions): `SubmitHtmlResponseTest` gained
  default-form tokenless/invalid-token HTML → honest error + nothing stored;
  `allow_nojs` form tokenless HTML → stored + delivered (via `FakeMailer`) +
  success page; `allow_nojs` + honeypot-filled HTML → generic success page
  but discarded, mailer never called; `allow_nojs` + forged token HTML →
  still silent discard; two JSON-path regression locks (default and
  `allow_nojs` forms both keep the original fake-success-and-discard
  behaviour). `EmbedPanelTest` extended to assert the snippet's static
  honeypot markup. `AdminUiTest` gained a checkbox round-trip test (defaults
  off, posts on, re-render shows checked, omitting the field on a later post
  clears it). `SchemaMigrationsTest`/`MigrationRunnerTest` updated for the
  9th migration. Full suite green.
- No new Composer dependencies or vendored assets (none authorised, none
  needed).
- QUESTIONS.md: both Increment 7 items resolved with decision notes (#1 per
  the ruling above; #2 — the 15.4 KB `osf.js` size — ratified as-is, no code
  change).

## 2026-08-27 — Housekeeping: dev-server router, Composer timeout, root
## landing, migrate command
- Branch: chore/dev-serve-and-migrate (off latest main).
- `public/dev-router.php` for `composer serve`: PHP's built-in server 404s a
  request whose path resembles a static file (e.g. `/embed/manual.html`)
  instead of handing it to a router script; this router serves an existing
  file under `public/` natively (`return false`) and otherwise requires
  `public/index.php`. Dev-only — headed comment states it's never the
  production entry point. `composer.json` `serve` script updated to pass it.
- `composer.json`: `config.process-timeout` set to `0` — the default 300s was
  killing `composer serve` five minutes into every dev session.
- `GET /` route added (`Routes.php`): 302 to `/admin/login`. Previously an
  installed instance's root URL fell through to Slim's raw 404 (no route
  registered at all); the existing `InstallStateMiddleware` already redirects
  everything to `/install` when not installed, so that half needed no change.
- `bin/osf migrate`: new explicit command, checked for and documented as the
  fix for a live incident (migration 009 `allow_nojs` shipped, an
  already-installed instance that only unzipped the new code kept hitting
  `Undefined array key "allow_nojs"` warnings on every form read because
  nothing had ever re-run the migrator against it). Checks
  `Paths::isInstalled()` itself — error + exit 1 before the browser installer
  has run — then runs `MigrationRunner::migrate()`, prints each newly-applied
  filename or "Already up to date.", exit 0. Idempotent; safe to re-run. The
  pre-existing implicit auto-migrate at every other `bin/osf` command's boot
  is unchanged.
- `MigrationRunner::pendingCount()` added (directory listing + one SELECT, no
  schema changes) and wired into `AdminController::dashboard()` to drive a
  new non-dismissible red banner in `templates/admin/dashboard.php`:
  "Database update required — run bin/osf migrate (N pending)". Dashboard
  only; deliberately no auto-migration triggered by any web request.
- Removed a stray untracked `public/embed/manual.html` left over from local
  testing; `Routes::embedManual` serves the real one from
  `tests/embed-manual.html` (the single source), unaffected.
- Tests (+11, 415 total, 2516 assertions): `DevRouterTest` (router file
  content/shape, serve script + process-timeout wiring in composer.json);
  `Http/RootRedirectTest` (installed root → 302 `/admin/login`);
  `Cli/CliMigrateTest` (refuses pre-install; applies pending migrations to a
  stale DB fixture built from copies of the real numbered migration files and
  reports each one; idempotent second run reports "Already up to date.";
  fresh install applies all nine); `Admin/DashboardStaleSchemaTest` (banner
  present against a version-8 fixture DB, absent on a current schema). Full
  suite green.
- No new Composer dependencies. Nothing logged to QUESTIONS.md — no
  architecture/security/scope questions arose.

## 2026-08-27 — Patch: admin deletion with guards
- Branch: fix/admin-delete.
- Architect ruling reversal: 5c had deferred admin deletion by design
  (deactivation-only, see 5c entry below); this patch adds a guarded hard
  delete alongside it. Deactivation remains the reversible option.
- `AdminRepository::deleteAdmin(int $id): bool` — prepared `DELETE`,
  returns whether a row was actually removed (`PDOStatement::rowCount()`).
- `AdminsController`: new `deleteConfirm` (GET) / `delete` (POST) actions at
  `/admin/admins/{id}/delete`. Three server-enforced guards, re-checked on
  the POST regardless of what the GET step showed (so a forged POST can't
  bypass them): target must exist; the last remaining ACTIVE admin can never
  be deleted (deleting an INACTIVE admin is always allowed, whatever the
  active count — checked first, mirroring the existing deactivate guard); an
  admin can never delete their own account (checked second — structurally,
  an HTTP-reachable "last active but not self" case can't occur, since the
  acting admin must themselves be active to be logged in at all, so this
  ordering only affects which message a simultaneous self+last-active hit
  shows). The POST also re-verifies the ACTING admin's current password
  (`PasswordHasher::verify`, same re-authentication pattern as
  `AccountController`); a wrong password re-renders the confirmation screen
  with a 401 and an error instead of redirecting.
- New template `templates/admin/admin_delete_confirm.php`: shows the
  target's email, states plainly that deletion is permanent/irreversible,
  and the current-password field. `templates/admin/admins.php` gets a
  "Delete" action per row, hidden for the signed-in admin's own row and for
  the last active admin (same visibility rule the guards enforce).
- `bin/osf admin:delete ID`: refuses the last active admin (deleting an
  inactive one always allowed), prints what it deleted. Deliberately no
  password prompt — shell access to run `bin/osf` is already a higher
  privilege than the admin panel itself, documented inline and in the CLI
  usage text.
- README "Admin model" section rewritten: deletion documented with its
  three guards, deactivation reframed as the reversible alternative
  (previously said accounts are "never deleted").
- Tests (+14, 429 total): `Auth/AdminRepositoryTest` (`deleteAdmin` removes
  the row / returns false for an unknown id); `Admin/AccountAdminsHttpTest`
  guard matrix (delete button hidden for self and for the last active admin;
  self-delete refused by a forged POST even with a second active admin
  present; last-active-admin delete refused server-side with its own
  message; an inactive admin is always deletable; wrong current password
  re-renders 401 with an error and does not delete; confirmation screen
  shows the target's email and states permanence; success path removes the
  row, redirects, and flashes); `Cli/CliAdminTest` (`admin:delete` happy
  path, refuses the last active admin, deleting inactive always allowed,
  refuses an unknown id, refuses a non-numeric id). Full suite green.
- No new Composer dependencies. Nothing logged to QUESTIONS.md.

## 2026-08-27 — Increment 8: packaging (release zip, versioning, upgrade path)
- Branch: feature/increment-8-packaging (off latest main).
- Version bumped to **0.1.0** — the first packaged version. `src/Version.php`
  stays the single source of truth; the build reads it to name the zip and
  everything else derives from it. `VersionTest` locks the semver shape and the
  0.1.0 value.
- `bin/build-release.php`: PHP build script (dev container / CI only). Exports
  committed HEAD via `git archive` into a temp `opensendform/` folder, runs
  `composer install --no-dev --optimize-autoloader` inside it, prunes the
  exclusion list, writes `INSTALL.txt` (short: upload → docroot at public/ or
  fallback → visit /install; upgrade: replace all except var/, run migrate) and
  `vendor/.htaccess`, then zips to `dist/opensendform-v{VERSION}.zip`. `dist/`
  gitignored. Excluded: tests/, .devcontainer, .github, .claude, .git,
  .gitignore, phpunit.xml, the four state files, and the build tooling itself
  (build-release/verify-release/release_lib). `dev-router.php` ships (inert in
  prod); `tests/embed-manual.html` does not (tests/ excluded wholesale).
- `bin/verify-release.php`: unzips the artefact to a temp dir and asserts no
  excluded path leaked, every required path is present (public/index.php,
  public/embed/osf.js, the seven-file .htaccess set, vendor/autoload.php,
  migrations/*.sql, bin/osf, LICENSE, INSTALL.txt, src/Version.php), the shipped
  autoloader resolves `OpenSendForm\Version` (real `php -r` smoke), and the
  embedded version matches src/Version.php + the zip name + INSTALL.txt.
  Non-zero exit on any failure. Manually confirmed it fails on a missing zip and
  on a zip with an injected excluded path.
- `bin/release_lib.php`: shared, side-effect-free helpers (version read,
  recursive delete, checked command runner, zip/unzip). zip/unzip prefer
  `ZipArchive` and fall back to the `zip`/`unzip` CLIs — this container has no
  zip extension (see QUESTIONS.md Increment 8 #1).
- `.htaccess` set: deny-all in var/, migrations/, bin/, templates/, src/
  (committed; a `.gitignore` exception tracks var/.htaccess) and vendor/
  (written at build). `public/.htaccess`: front-controller rewrite + gzip
  (mod_deflate) + one-month cache for static assets (mod_expires) + `-Indexes`.
  Each rule carries a plain-language comment. Only the fallback layout relies on
  the deny files; the recommended docroot-at-public/ layout does not.
- `bin/osf version`: prints app version + current schema version (highest
  applied migration) + pending-migration count. Runs BEFORE the auto-migrate
  boot so the pending count is truthful; read-only; reports n/a when not
  installed (and no DB_DSN override) so it never creates a stray database.
- CI: new `package` job (PHP 8.1 leg, `zip` extension) runs build + verify on
  every PR/push to main and uploads the zip via `actions/upload-artifact@v4`
  (`if-no-files-found: error`). Existing `tests` job unchanged.
- README: new "Releases & upgrading" section — the release zip's contents, the
  cPanel install walk-through (create subdomain → upload+extract → docroot to
  public/ → /install), the fallback .htaccess layout for docroot-locked hosts,
  the upgrade procedure (replace all except var/, then `bin/osf migrate` or the
  dashboard banner), and exactly what var/ preserves. Installing intro updated
  (the zip now exists).
- Tests (+6, 435 total): `VersionTest` (semver + 0.1.0), `Cli/CliVersionTest`
  (`bin/osf version` against a throwaway DB reports schema 0 and all migrations
  pending — proves it does not auto-migrate first), `Release/ReleaseManifestTest`
  (tokenises both scripts and asserts the build/verify exclusion lists stay in
  sync, plus spot-checks the required list). The build/verify scripts themselves
  are exercised by the CI package job, not PHPUnit. Full suite green.
- Deviation: the zip PHP extension the prompt assumed is absent from the
  container; handled with a ZipArchive-or-CLI fallback + `zip` ext in CI. Logged
  to QUESTIONS.md Increment 8 #1. No new Composer dependencies.

## 2026-08-27 — Increment 5d: design-system overhaul (token contract + GitHub/Starlight parity)
- Branch: feature/increment-5d-design (off latest main).
- Single source of colour: new `public/assets/tokens.css` declares the
  `--osf-*` contract (surfaces, text, borders, accent, status +subtle
  variants, focus, type, shape) on `:root` (dark default) and
  `[data-theme="light"]`. Values are @primer/primitives v11.10.0 (MIT)
  resolved hex, fetched from the vendored functional dark/light theme files;
  each token is annotated with the Primer source token it maps from (bg=
  bgColor-default, text=fgColor-default, accent=fgColor-accent, etc.).
  `data-palette="github"` set on `<html>` and reserved for future palettes.
  Colour pairs are Primer's own AA-clearing pairs (not degraded).
- Pico.css retired (`public/assets/vendor/pico.min.css` removed); `admin.css`
  rewritten from scratch as a bespoke, token-only stylesheet — element
  defaults + the component set the admin/installer actually use. No hardcoded
  colour outside tokens.css (the one fixed exception, the QR quiet-zone white,
  is a token, `--osf-qr-bg`).
- Navigation: `_nav.php` rebuilt as a TOP header bar (`.osf-header`) on
  `--osf-bg-raised` with a hairline border — NO sidebar/tree/search/
  breadcrumbs. Brand left; six links (Dashboard/Forms/Submissions/Email/
  Admins/Account, active = accent-subtle bg + accent text via aria-current);
  external Docs link (opensendform.com, book-open icon, target=_blank
  rel=noopener, "opens in a new tab" for AT); theme toggle; admin name;
  logout. Links WRAP on narrow viewports (no hamburger/JS menu).
- Theme: default dark; toggle cycles Dark/Light/Auto (Auto = prefers-color-
  scheme), persisted in localStorage `osf-theme`. New external
  `public/assets/theme-init.js` is the FIRST element in `<head>`, blocking;
  it sets `data-theme` (resolved) + `data-theme-mode` (choice) before paint —
  no flash. The old `theme.js` apply path was deleted; the toggle UI logic
  moved into the deferred `admin.js`. Fixes the known invisible-in-light
  toggle glyph: icons are currentColor SVGs, shown per `data-theme-mode`.
- Icons: vendored Lucide subset (ISC, licence note retained) as
  `src/Admin/icons.php` — helper `icon($name,$class,$label)` returning inline
  SVG, loaded by `TemplateRenderer` alongside `helpers.php`. The 15 required
  glyphs (sun/moon/monitor/copy/download/trash-2/pencil/check/x/alert-triangle/
  info/book-open/log-out/eye/eye-off) render valid `<svg>` with
  stroke=currentColor; unknown names render ''. Wired into nav, flash, copy/
  delete/edit buttons.
- Responsive: data tables carry `class="osf-table"` + per-cell `data-label`,
  collapsing to stacked label+value cards under 640px (no horizontal scroll,
  all info visible). Submission/dashboard last-error cells are no-JS
  `<details>` expanders showing the full text. Destructive actions (Disable/
  Deactivate/Delete/Disable-2FA) use the `.osf-danger` (danger + danger-subtle)
  treatment; deactivated admin rows are muted; flash/banners use status-subtle
  backgrounds + matching icons.
- Installer shares the design system (same tokens.css/admin.css/theme-init.js;
  no admin nav). Housekeeping: `.devcontainer/Dockerfile` now installs the
  `zip` PHP extension (libzip-dev + docker-php-ext-install zip) — closes the
  Increment 8 base-image gap on the next container rebuild; the CLI fallback
  stays.
- Tests: reworked the 5b asset-reference tests (pico refs → tokens.css +
  admin.css + theme-init.js on every admin AND installer page; pico/theme.js
  asserted ABSENT) in AdminUiTest + InstallerHttpTest, and added a live nav/
  Docs-link assertion. New `tests/Admin/DesignSystemTest.php`: the
  no-hardcoded-colours grep enforcement (templates + assets, exempt tokens.css
  + vendor/qrcode.js), theme-init-first-in-head, the full token contract,
  icon-renders-valid-SVG (+ ISC note), top-nav/Docs/no-sidebar shape, and the
  card-collapse + details/summary hooks. Full suite green: 444 tests, 2878
  assertions (was 435).
- README: the existing Theming note rewritten around the token file, the
  data-palette reservation, the shared-with-docs contract, and the no-flash
  bootstrap.
- Scope fence honoured: `public/embed/osf.js` and its injected styles, and the
  public API, are untouched. No new Composer dependencies. Nothing logged
  blocking to QUESTIONS.md (one assumption noted there).
