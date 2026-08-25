# OpenSendForm — current state

Last updated: 2026-08-25 (feature/increment-5c-account, Claude Code)

## Status
The public submission endpoint is live end-to-end and RELAYS BY EMAIL.
On top of the Increment 1 data model there is a versioned v1 API (token
endpoint, CORS preflights, `POST /v1/form/{form_key}/submit`) driven by an
ordered validation/abuse pipeline. Submissions that pass are stored, then a
single in-request SMTP send is attempted; failures are retried by an
operator cron. Increment 4 added optional per-form Cloudflare Turnstile.
Increment 5a added the ADMIN AUTHENTICATION STACK (argon2id, optional TOTP
2FA + recovery codes, CSRF, session hardening). Increment 5b added the
ADMIN DESIGN SYSTEM + CRUD SCREENS: Pico.css-based light/dark UI, dashboard,
forms CRUD, submissions list with retry, and the TOTP enrolment UX (QR,
segmented inputs, recovery-code copy/download). A follow-up patch
(fix/5b-ux-defects, this sprint) fixed field-tested UX defects on the TOTP
login screen: a styled/prominent error alert and a same-field recovery-code
toggle, plus a regression test guarding every admin screen against shipping
unstyled. Increment 5c added ADMIN ACCOUNT MANAGEMENT + 2FA LIFECYCLE UX:
the single-tenant admin model made explicit (is_active flag, deactivation in
place of deletion), a self-service account screen, an admins roster with a
last-active guard, a dashboard 2FA nudge, and a full 2FA disable flow. Test
suite green (296 tests). CI runs it on every PR/push.

## Product definition
Free, open-source, self-hostable form-to-email service for shared
cPanel/PHP hosting. One installation serves many websites via embedded
snippet + JS posting to a central endpoint. Validated, abuse-filtered,
relayed by authenticated SMTP to the site owner.

## Decisions locked
- Name: OpenSendForm. Stack: PHP 8.1+ / Slim 4 / Composer / PHPMailer /
  SQLite default (MySQL optional via PDO). Server-rendered admin UI on
  Pico.css; no JS framework, no build step; JS is enhancement-only.
- All SQL portable across sqlite + mysql (portable types; date arithmetic
  in PHP, not SQL). Timestamps stored as UTC `Y-m-d H:i:s` TEXT.
- Form keys are PUBLIC identifiers, stored plain. A "form" is the unit of
  config (key, recipient, origins, toggles).
- Response contract (FROZEN — embed JS builds against it): JSON only.
  Success `{"ok":true}`; failure `{"ok":false,"error":{"code","message"}}`.
  HTTP: 200 ok, 400 validation, 403 key/origin, 405 method, 413 too large,
  429 rate limited.
- Bot-facing checks fail SILENTLY; an authentic but EXPIRED token returns an
  honest `400 token_expired`.
- Storage: metadata always stored. Content is always stored as the in-flight
  delivery payload; `store_content` means "retain content after successful
  delivery" (cleared on `sent` unless on; `failed`/`dead` keep it until the
  retention purge). See QUESTIONS.md item 1 (RESOLVED).

## Mail relay (Increment 3) — condensed; see HISTORY for detail
- Policy (Mail\MessageBuilder): From ALWAYS the service address; Reply-To the
  submitter's `email` only when valid; no other submitter data in a header.
- `Mail\DeliveryService`: `attemptDelivery()` (success → `sent` + clearContent
  unless store_content; failure → `failed` + backoff, `dead` at
  MAIL_MAX_ATTEMPTS) and `retryDue()`. Fake-clock testable; FakeMailer double
  is scriptable (alwaysFail/failFirst). Config: MAIL_ENABLED, SMTP_*,
  MAIL_MAX_ATTEMPTS (5), MAIL_RETRY_BACKOFF_MINUTES. `bin/osf` mail:retry etc.

## Turnstile (Increment 4) — condensed; see HISTORY for detail
- Optional PER FORM: enabled iff both `turnstile_sitekey` + `turnstile_secret`
  stored (migration 006). Both-or-neither via `FormRepository::setTurnstile()`.
- Verifier interface + Curl impl + fake; `TurnstileStage` fail-open. Secret
  NEVER exposed by any endpoint. `bin/osf form:turnstile`.

## Admin authentication (Increment 5a) — condensed; see HISTORY for detail
- Migration 007 `admins`. `src/Auth/*`: PasswordHasher (argon2id), Base32,
  Totp (RFC 6238, otpauth URI, QR-less), RecoveryCodes, AdminRepository.
- Session seam `SessionInterface` (NativeSession lazy-start, strict mode,
  HttpOnly/SameSite=Strict/Secure; FakeSession for tests). `AuthService`
  (LoginOutcome/TotpOutcome enums, per-IP+per-email/admin rate limits, idle
  30 min / absolute 12 h, pending-TOTP 300s expiry). `Csrf` per session.
- `AdminController` + `AdminRoutes` + `templates/admin/*` via TemplateRenderer
  + `h()`. SecurityHeadersMiddleware wraps the group; AuthMiddleware protects
  all but login/totp. `bin/osf admin:create` (interactive password).

## Admin UI + design system (Increment 5b) — this sprint
- Vendored (MIT, headers + pins in-file): Pico.css v2.0.6
  (`public/assets/vendor/pico.min.css`); qrcode-generator v1.4.4
  (`public/assets/vendor/qrcode.js`). No CDN at runtime; no new Composer deps.
- Our assets: `public/assets/admin.css` (brand palette light+dark via CSS
  custom properties, WCAG AA), `theme.js` (pre-paint theme apply + toggle,
  localStorage), `admin.js` (deferred enhancements: click-to-copy, segmented
  TOTP boxes, client-side QR, recovery copy/download/gate).
- Layout wires Pico + admin.css + theme.js; `_nav.php` (Dashboard/Forms/
  Submissions + theme toggle + admin name + CSRF logout) and `_flash.php`
  partials; `Admin\Flash` (session-backed one-time notices) and
  `Admin\AdminView` (authenticated-page chrome renderer).
- CSP on ALL /admin responses (SecurityHeadersMiddleware::CSP): `default-src
  'self'; script-src 'self'; style-src 'self'; img-src 'self' data:;
  frame-ancestors 'none'`. Public JSON API carries no CSP. No inline
  scripts/styles/handlers anywhere; every screen works with JS off.
- Dashboard: active-form/today/failed/dead counts + 10 most recent
  failed/dead (metadata only) linking to the filtered submissions view.
- Forms: `Admin\FormsController` + `forms_list.php`/`form_edit.php`. List
  (key copy button, active + Turnstile badges). Create/edit reuse
  FormRepository validation (origins textarea ↔ JSON array, store_content
  inline-explained, retention 1–3650, is_active, Turnstile both-or-neither).
  Secret write-only: never echoed, set/not-set hint, blank keeps existing,
  cleared sitekey disables. Inline 422 re-render preserves input (not secret).
  Enable/disable from the list.
- Submissions: `Admin\SubmissionsController` + `submissions.php`. Paginated
  50/page, filter by status + form; per-row Retry + bulk "Retry all due now"
  drive DeliveryService (guarded when no mailer) and return to the same view.
  METADATA ONLY — content never displayed (WHY in a template comment).
- TOTP UX: setup QR rendered client-side from a data-attribute (secret never
  leaves the page; manual key/URI are the no-JS fallback); login + setup code
  fields become six auto-advancing/paste/auto-submit boxes built from the
  plain input (degrade to one field); recovery screen has copy-all,
  download-.txt (Blob) and a "saved" checkbox gating continue (plain list
  without JS). AdminController renders these via AdminView (+ qrcode.js).
  Login-time `/admin/totp` (fix/5b-ux-defects): ONE field (`name="code"`,
  no length/pattern limits) serves both a TOTP code and a recovery code —
  the no-JS fallback is that same plain input, not a second form. With JS,
  `admin.js`'s `data-totp-recovery-toggle` attribute (set only on this
  field, not on `totp_setup.php`'s enrolment field) adds a "Use a recovery
  code instead" link that swaps the six boxes for the plain input in place
  (same field, no auto-submit) and back. Errors render via the same
  `osf-flash osf-flash--error` class the other TOTP screens already used
  (previously login-time errors were unstyled plain text).

## Admin account mgmt + 2FA lifecycle (Increment 5c) — this sprint
- SINGLE-TENANT model, now explicit + documented: every admin co-operates on
  one installation and sees all forms/submissions; no roles/permissions. No
  account deletion — retirement is deactivation.
- Migration 008 adds `admins.is_active INTEGER NOT NULL DEFAULT 1`.
  `AdminRepository` gains `listAll/countActive/setActive/updateDisplayName/
  updateEmail` and hydrates `is_active`. `AuthService`: an inactive admin is
  refused login with the SAME generic Invalid outcome (no status disclosure),
  and `currentAdmin()` invalidates a live session for a now-inactive admin on
  its next request.
- `Admin\AccountController` + `account.php` (nav "Account" link replaces the
  bare name): change display name (CSRF only); change email (current password
  + validation + uniqueness); change password (current password + new ≥ 12
  twice, session id regenerated on success). All CSRF POSTs, flash feedback.
- `Admin\AdminsController` + `admins.php`: roster (email, name, 2FA badge,
  active badge, last login); create admin (initial password ≥ 12, guidance to
  change after first login); deactivate/reactivate. GUARD (server-enforced +
  button hidden): the last remaining ACTIVE admin can never be deactivated,
  including self-deactivation when last active. No delete.
- 2FA nudge: `AdminController::dashboard` sets `showNudge` when TOTP off and
  not dismissed this session; `dismissNudge` (CSRF POST) sets the session
  flag. Banner in `dashboard.php`.
- 2FA disable: `AdminController::disableTotp` on the enabled totp/setup view —
  current password AND current TOTP code required; clears totp_secret/
  totp_enabled/recovery_codes, flashes, re-arms the nudge (removes dismiss
  flag). Separated `.osf-danger-zone` section in `totp_setup.php`.
- Recovery UX: login `totp.php` states "Enter ONE of your recovery codes" +
  10-char hint; `recovery_codes.php` renders codes one-per-line in a
  monospace `.osf-recovery-block` (`<pre>`, copy-all preserves line breaks)
  and says each works exactly once; the regenerate-confirm input now uses the
  same `data-totp-code` six-box enhancement as login (no divergent markup).

## Submission pipeline order (enforced + tested)
method/body size → field hygiene → form lookup by URL key → origin allowlist
→ per-IP then per-form rate limits → honeypot → token → Turnstile (optional)
→ email (syntax + MX/A) → store → delivery (terminal, always succeeds).
Locked by SubmitPipelineOrderTest.

## What exists now
Increments 0–5b as before, plus 5c: `migrations/008_admins_is_active.sql`;
`src/Admin/{AccountController,AdminsController}.php`; `templates/admin/{account,
admins}.php` (+ reworked _nav/dashboard/totp/totp_setup/recovery_codes);
`AdminRepository`/`AuthService`/`AdminController` additions; `.osf-nudge/
.osf-danger-zone/.osf-recovery-block` in admin.css; and
`tests/Admin/AccountAdminsHttpTest.php`. Only hard Composer dep remains
phpmailer/phpmailer ^6. Two vendored front-end assets (Pico, qrcode).

## Known gaps / not built (by design this sprint)
- Installer, embed snippet/JS (incl. Turnstile widget rendering), synthetic
  monitoring, packaging — later increments.
- Password RESET by email, roles/permissions, account deletion, audit log,
  remember-me — out of scope for 5c.
- Disposable-domain blocklist, trusted-proxy IP handling, DKIM/SPF guidance.
- CurlTurnstileVerifier's real curl and NativeSession's real $_SESSION path
  remain un-unit-tested (network/globals); covered via fakes.
- admin.css/theme.js/admin.js run only through PHP asset-reference + no-inline
  tests and `node --check`; no browser/DOM test harness (no Node in prod).

## Open items
- QUESTIONS.md: all prior items RESOLVED; no new questions this sprint.
- Increment 6 (browser installer + environment autodetection) next; the
  "first admin from the installer" referenced by the Admins screen/README
  lands there (CLI `admin:create` is the interim path).

## Planned increment sequence (subject to revision)
0. Skeleton. 1. Schema. 2. Submission pipeline. 3. SMTP relay. 4. Turnstile.
5a. Admin auth. — ALL DONE
5b. Admin design system + form/submission CRUD screens. — DONE
5c. Admin account management + 2FA lifecycle UX. — DONE
6. Browser installer + environment autodetection.
7. Embed snippet + JS.
8. Synthetic monitoring + alerting.
