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
