# OpenSendForm — current state

Last updated: 2026-08-24 (feature/increment-5a-admin-auth, Claude Code)

## Status
The public submission endpoint is live end-to-end and RELAYS BY EMAIL.
On top of the Increment 1 data model there is a versioned v1 API (token
endpoint, CORS preflights, `POST /v1/form/{form_key}/submit`) driven by an
ordered validation/abuse pipeline. Submissions that pass are stored, then a
single in-request SMTP send is attempted; failures are retried by an
operator cron. Increment 4 added optional per-form Cloudflare Turnstile.
This sprint (Increment 5a) added the ADMIN AUTHENTICATION STACK: a
server-rendered `/admin` login with argon2id passwords, optional TOTP 2FA
(+ recovery codes), per-session CSRF, session hardening and idle/absolute
timeouts. The admin UI is FUNCTIONAL BUT UNSTYLED (5b brings the design
system). Test suite green (230 tests). CI runs it on every PR/push.

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

## Mail relay (Increment 3 + follow-up) — condensed; see HISTORY for detail
- Policy (hard rules, enforced by `Mail\MessageBuilder`): From is ALWAYS the
  service address; Reply-To is the submitter's `email` only when valid; no
  other submitter data reaches a header; subject = form name (control chars
  stripped); body = sanitised/capped field listing.
- `Mail\MailerInterface` (`send(to, replyTo?, subject, body)`, throws) →
  `PhpMailerMailer` (SMTP from Config) + `tests/Support/FakeMailer`.
  `Mail\DeliveryService` builds+sends: success → `sent` (content cleared via
  `clearContent()` unless store_content); failure → `failed` (+attempts/
  last_error/next_attempt_at) then `dead` at MAIL_MAX_ATTEMPTS; `retryDue()`
  re-attempts due rows (fake-clock testable). `DeliveryStage` is terminal and
  ALWAYS returns success (a send failure never changes `{"ok":true}`); skips
  when no mailer/MAIL_ENABLED falsy/SMTP_HOST empty/nothing stored.
- Config mail keys: MAIL_ENABLED (primary switch, `mailEnabled()`), SMTP_USER/
  PASS/ENCRYPTION (none|starttls|smtps), MAIL_FROM_ADDRESS/NAME,
  MAIL_MAX_ATTEMPTS, MAIL_RETRY_BACKOFF_MINUTES. Migration 005 adds the
  submissions retry columns. `bin/osf`: `mail:retry` (cron), `mail:test`,
  `submissions:list` (metadata only).

## Turnstile (Increment 4) — condensed; see HISTORY for detail
- Optional PER FORM (no global switch): enabled iff both `turnstile_sitekey`
  and `turnstile_secret` are stored (migration 006). Both-or-neither via
  `FormRepository::setTurnstile()`.
- `Turnstile\TurnstileVerifierInterface` → enum VALID/INVALID/UNAVAILABLE,
  `CurlTurnstileVerifier` (curl to Cloudflare siteverify, tight timeouts, any
  error → UNAVAILABLE) + `FakeTurnstileVerifier`. `Submit\Stages\TurnstileStage`
  (between token and email; client token `_osf_cf`): missing → 400
  `turnstile_required`, positively-invalid → 400 `turnstile_failed`; FAIL-OPEN
  (VALID and UNAVAILABLE both proceed). Token endpoint advertises the sitekey
  only (secret NEVER exposed). Verifier injected via AppFactory/SubmitPipeline.
- `bin/osf form:turnstile ID --sitekey=X --secret=Y` (enable) / `... --disable`
  (clear both).

## Admin authentication (Increment 5a)
- Migration 007 adds `admins` (email UNIQUE, display_name, password_hash,
  totp_secret NULL, totp_enabled, recovery_codes NULL (JSON of hashes),
  created_at/updated_at, last_login_at NULL). Portable types.
- `src/Auth/*` primitives (no new deps): `PasswordHasher` (argon2id when
  `PASSWORD_ARGON2ID` is defined, else PASSWORD_DEFAULT; algorithm injectable;
  hash/verify/needsRehash; not final so tests spy verify()), `Base32` (RFC
  4648, unpadded encode, padding-agnostic decode), `Totp` (RFC 6238
  SHA1/6-digit/30s, +/-1 window, hash_equals, otpauth URI, secret via
  random_bytes), `RecoveryCodes` (10×10-char, JSON password_hashes,
  single-use consume, input-tolerant).
- `AdminRepository` (prepared statements): createAdmin (hashes), findByEmail
  (case-insensitive)/findById, recordLogin, updatePassword/updatePasswordHash,
  setTotp/enableTotp/disableTotp, setRecoveryCodes, consumeRecoveryCode.
- Session seam: `SessionInterface` (get/set/remove/regenerate/destroy) with
  `NativeSession` (lazy start — public API never gets a cookie; HttpOnly,
  SameSite=Strict, Secure on HTTPS; regenerate/destroy) and a
  `tests/Support/FakeSession` (array-backed, counts regenerate/destroy).
  $_SESSION is touched ONLY inside NativeSession.
- `AuthService`: attemptLogin → `LoginOutcome` enum (RateLimited/Invalid/
  NeedsTotp/Success). Rate-limited per-IP AND per-email via the existing
  RateLimiter. Unknown email still runs a verify against a lazy dummy hash
  (timing); unknown email and wrong password both return Invalid (no
  enumeration). Opportunistic rehash on login when needsRehash. Session id
  regenerated on every privilege change; idle timeout 30 min, absolute 12 h
  enforced in currentAdmin(). verifyTotp() accepts a TOTP code or a single-use
  recovery code. `Csrf` (issue/validate, hash_equals, per session).
- Routes (`AdminRoutes` + `AdminController`, plain-PHP `templates/admin/*` via
  `TemplateRenderer` + `h()` escaper): GET/POST `/admin/login`, GET/POST
  `/admin/totp` (incl. recovery path), POST `/admin/logout`, GET `/admin`
  (placeholder dashboard: name + logout), enrolment GET/POST
  `/admin/totp/setup` (+ POST `/admin/totp/recovery-codes/regenerate`, gated
  on a current TOTP code). `SecurityHeadersMiddleware` (X-Frame-Options DENY,
  X-Content-Type-Options nosniff, Referrer-Policy no-referrer, Cache-Control
  no-store) wraps the whole group; `AuthMiddleware` protects all but
  login/totp.
- Wiring: `AppFactory::create` gains a 7th optional arg `?SessionInterface`
  (defaults to NativeSession; tests inject FakeSession) and registers the auth
  stack in the container, then calls `AdminRoutes::register`.
- CLI: `bin/osf admin:create --email= --name=` prompts for the password
  interactively (hidden via `stty` on a TTY, visible fallback with a warning),
  refuses < 12 chars, prints nothing sensitive.

## Submission pipeline order (enforced + tested)
method/body size → field hygiene (+ reserved `_osf_token/_osf_hp/_osf_cf`) →
form lookup by URL key → origin allowlist (sets CORS) → per-IP then per-form
rate limits → honeypot → token → Turnstile (optional, per form) → email
(syntax + MX/A, if `email` field present) → store (non-terminal, content
always persisted) → delivery (terminal, always succeeds). Locked by
SubmitPipelineOrderTest.

## What exists now
Increments 0–4 as described above and in HISTORY (public v1 API + abuse
pipeline, SMTP relay with retry, per-form Turnstile). Only new hard
dependency to date is phpmailer/phpmailer ^6 (Increment 3). This sprint
(Increment 5a) added `src/Auth/*`, `src/Admin/*`, `templates/admin/*`,
migration 007, the `SessionInterface` seam + `FakeSession`/
`CountingPasswordHasher` test doubles, `bin/osf admin:create`, and the
AppFactory session arg + container wiring. No new dependencies.

## Known gaps / not built (by design this sprint)
- Admin CRUD for forms/submissions, any CSS/design system (5b), password
  reset by email, remember-me, multi-admin management UI, installer
  integration — all deferred (out of scope for 5a).
- Disposable-domain blocklist, installer, embed snippet/JS (incl. the
  Turnstile WIDGET rendering — increment 7), trusted-proxy IP handling.
- No repository method to toggle store_content/retention yet (admin UI).
- DKIM/SPF guidance deferred to the installer increment.
- CurlTurnstileVerifier's real curl call is not unit-tested (would hit the
  network); the interface, fake and pipeline behaviour are fully covered.
- NativeSession's real $_SESSION/cookie path is not unit-tested (globals);
  the whole admin flow is covered through FakeSession instead.

## Open items
- QUESTIONS.md: all prior items RESOLVED; no new questions this sprint.
- Increment 5b (admin styling / design system) next.

## Planned increment sequence (subject to revision)
0. Composer/Slim skeleton, PHPUnit harness, SQLite storage. — DONE
1. Schema + form/API-key model. — DONE
2. Submission endpoint + validation/abuse middleware stack. — DONE
3. SMTP relay (PHPMailer) with retry. — DONE
4. Turnstile integration. — DONE
5a. Admin auth stack (argon2id, TOTP, sessions, CSRF). — DONE
5b. Admin design system + form/submission CRUD screens.
6. Browser installer + environment autodetection.
7. Embed snippet + JS.
8. Synthetic monitoring + alerting.
