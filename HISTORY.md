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
