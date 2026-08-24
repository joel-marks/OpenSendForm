# OpenSendForm — current state

Last updated: 2026-08-24 (chore/ci-workflow, Claude Code)

## Status
The public submission endpoint is live end-to-end (storage only; no mail
yet). On top of the Increment 1 data model there is a versioned v1 API: a
token endpoint, CORS preflights, and `POST /v1/form/{form_key}/submit`
driven by an ordered validation/abuse pipeline. Submissions that pass are
stored at status `received`. Test suite green (88 tests). CI now runs
that suite automatically on every PR and on push to main.

## CI
`.github/workflows/ci.yml` — job `tests` on ubuntu-latest, matrix PHP 8.1
+ 8.2 (fail-fast off, so a break on either floor or newer runtime is
visible independently). Triggers: pull_request, push to main. Steps:
checkout, `shivammathur/setup-php@v2` (pdo_sqlite, coverage off,
XDEBUG_MODE=off), Composer cache keyed on `composer.lock`, `composer
install --no-interaction --prefer-dist`, `composer test`. README badge
added under the title, linking to the workflow's Actions page.

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
  Codes are stable snake_case; messages may change. HTTP: 200 ok, 400
  validation, 403 key/origin, 405 method, 413 too large, 429 rate limited.
- Bot-facing checks fail SILENTLY (normal `{"ok":true}`, nothing stored):
  filled honeypot; missing / forged / too-young token. EXCEPTION: an
  authentic but EXPIRED token returns an honest `400 token_expired` so a
  real user with a long-open page can refresh and retry.
- Rate limiting uses REMOTE_ADDR only; trusted-proxy/forwarded-for support
  is a deliberate future config concern (noted in code).
- Storage: metadata always stored; message content only when the form's
  store_content toggle is on. Retention purge computed per form in PHP.

## What exists now
Increments 0–1 (unchanged): composer project, `public/index.php`,
`AppFactory`, `Config`, `Version`, `Storage/{Database,MigrationRunner}`,
`migrations/001–003`, `Form/{FormKey,FormRepository}`,
`Submission/SubmissionRepository`, `bin/osf` CLI, `GET /health`.

Increment 2 additions:
- Config: `APP_SECRET` (empty default; dev-only fallback
  'dev-secret-do-not-use' when APP_ENV=dev — installer generates the real
  one) plus env-overridable MAX_BODY_BYTES (65536), MAX_FIELDS (50),
  MAX_FIELD_NAME_BYTES (100), MAX_FIELD_VALUE_BYTES (10240),
  MIN_SUBMIT_SECONDS (3), TOKEN_MAX_AGE_SECONDS (3600),
  RATE_IP_PER_MINUTE (5), RATE_FORM_PER_HOUR (60). Typed accessors added.
- `migrations/004_create_rate_counters.sql` — bucket TEXT, window_start
  INTEGER, count INTEGER, PK (bucket, window_start).
- `src/Clock/{Clock,SystemClock}` — injectable unix-seconds clock.
- `src/RateLimit/RateLimiter` — fixed-window increment-and-check
  (`hit(bucket,limit,windowSeconds)`); portable read-then-write increment;
  per-bucket opportunistic pruning of stale windows.
- `src/Security/SubmitToken` — issue/verify `<ts>.<hmac_sha256(ts.key,
  secret)>`; VALID/TOO_YOUNG/EXPIRED/INVALID; hash_equals before age.
- `src/Validation/{DnsChecker,SystemDnsChecker}` — MX then A lookup; real
  DNS never hit in tests (tests/Support/FakeDnsChecker).
- `src/Http/OriginMatcher` — normalise/resolve/match origins (Origin
  header, Referer fallback). `src/Http/ApiResponse` — JSON contract + CORS.
- `src/Submit/` — Stage interface, SubmitContext, SubmitOutcome, nine
  Stages/ classes, and SubmitPipeline (assembles + runs the fixed order).
- Routes (v1): `GET /v1/form/{form_key}/token`, `POST
  /v1/form/{form_key}/submit`, and an OPTIONS preflight for each. The
  submit route is also mapped for other verbs so a wrong method returns
  the contract's 405. The form key travels only in the URL for both
  routes — never in the body — so both preflights resolve one specific
  form and echo its allowed origin exactly (see "CORS preflight" below).
- AppFactory wires Database/Clock/DnsChecker/repositories/RateLimiter/
  SubmitToken/SubmitPipeline into the container; `create()` accepts
  optional Database/DnsChecker/Clock for test injection.

## Submission pipeline order (enforced + tested)
method/body size → field hygiene (+ reserved `_osf_token/_osf_hp` split) →
form lookup by the URL's form_key → origin allowlist (sets CORS) → per-IP
then per-form rate limits → honeypot → token → email (syntax + MX/A, only
if an `email` field is present) → store. Cheapest/most-abuse-relevant
first; storage last. Order is locked by SubmitPipelineOrderTest and proven
behaviourally (bad origin + filled honeypot → origin_not_allowed).

## CORS preflight
Both `/v1/form/{form_key}/token` and `/v1/form/{form_key}/submit` preflight
identically: look up the ACTIVE form by the URL's key, match Origin (or
Referer fallback) against that form's own allowlist, and echo
`Access-Control-Allow-Origin` only on a match. Unknown/inactive key or a
non-matching origin: no ACAO header, nothing revealed about which origins
are registered anywhere else in the installation. This replaced an earlier
any-active-form preflight match for `/v1/submit` (form key used to travel
in the body) that was rejected as an origin-enumeration risk — see
QUESTIONS.md and the HISTORY.md entry for 2026-08-24.

## Known gaps / not built (by design this sprint)
- No mail sending (submissions stop at `received`), Turnstile,
  disposable-domain blocklist, admin UI, installer, embed snippet/JS,
  no-JS fallback, or trusted-proxy IP handling.
- No repository method yet to toggle store_content/retention (arrives with
  admin UI); defaults applied on create.
- Multipart parsing relies on PHP/Slim's parsed body; raw multipart is not
  re-parsed and file uploads are out of scope.

## Open items
- QUESTIONS.md: no open items currently.
- Increment 3 (SMTP relay via PHPMailer with retry) next.

## Planned increment sequence (subject to revision)
0. Composer/Slim skeleton, PHPUnit harness, SQLite storage. — DONE
1. Schema + form/API-key model. — DONE
2. Submission endpoint + validation/abuse middleware stack. — DONE
3. SMTP relay (PHPMailer) with retry.
4. Turnstile integration.
5. Admin panel + auth (argon2id, TOTP, CSRF).
6. Browser installer + environment autodetection.
7. Embed snippet + JS.
8. Synthetic monitoring + alerting.
