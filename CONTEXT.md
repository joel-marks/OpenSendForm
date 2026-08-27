# OpenSendForm — current state

Last updated: 2026-08-27 (fix/admin-delete, Claude Code)

## Status
The service is end-to-end: a versioned v1 API drives an ordered
validation/abuse pipeline; passing submissions are stored and relayed by
authenticated SMTP (in-request send + operator retry cron). Full admin panel
(auth, 2FA, forms/submissions CRUD, mail-setup wizard, browser installer),
a client-site embed artefact with a no-JS fallback, dev-server tooling and an
explicit migration command are all built and merged to main — see the
condensed sections below and HISTORY.md for full sprint-by-sprint detail.
This sprint (fix/admin-delete) is an architect ruling reversal: 5c had
deferred admin deletion by design (deactivation-only); admins can now be
permanently deleted, server-guarded — see "Admin deletion" below. Suite green
(429 tests). CI runs it on every PR/push.

## Product definition
Free, open-source, self-hostable form-to-email service for shared cPanel/PHP
hosting. One installation serves many websites via an embedded snippet + JS
posting to a central endpoint. Validated, abuse-filtered, relayed by
authenticated SMTP to the site owner.

## Decisions locked
- Name: OpenSendForm. Stack: PHP 8.1+ / Slim 4 / Composer / PHPMailer /
  SQLite default (MySQL optional via PDO). Server-rendered admin UI on
  Pico.css; no JS framework, no build step; JS is enhancement-only.
- All SQL portable across sqlite + mysql; timestamps stored as UTC `Y-m-d
  H:i:s` TEXT. Form keys are PUBLIC identifiers, stored plain.
- Response contract (FROZEN): JSON only by default. Success `{"ok":true}`;
  failure `{"ok":false,"error":{"code","message"}}`. HTTP 200/400/403/405/
  413/429. A client that prefers text/html (a native browser form POST) gets
  an HTML page instead — the JSON shape is unchanged for fetch/API callers.
- Bot-facing checks fail SILENTLY (filled honeypot; missing/forged/too-young
  token → fake success, nothing stored); an authentic but EXPIRED token
  returns an honest `400 token_expired`. Exception: on the HTML-negotiated
  (no-JS) path, a form with `allow_nojs=0` (default) instead returns an
  honest `400 javascript_required` for a missing/forged/too-young token —
  see "No-JS submission policy" below.
- Content always stored as the in-flight delivery payload; `store_content`
  means "retain content after successful delivery".
- Config precedence: defaults < var/config.php < environment (env always wins).

## Admin deletion (fix/admin-delete) — decision + guards
- RULING (reverses the 5c "no delete action, deferred by design" decision):
  admins can be hard-deleted (`AdminRepository::deleteAdmin`, prepared
  `DELETE`). Deactivation stays as the reversible alternative — both are
  offered from the Admins screen.
- Three server-enforced guards on both the web POST and `bin/osf
  admin:delete` (buttons also hidden client-side where applicable, but the
  guards are re-checked on the POST regardless): an admin can never delete
  themselves; the last remaining ACTIVE admin can never be deleted (deleting
  an INACTIVE admin is always allowed, whatever the active count — mirrors
  the pre-existing deactivate-guard); the acting admin must re-enter their
  own CURRENT password on the web confirmation step (wrong password
  re-renders with a 401 error, same re-authentication pattern as
  `AccountController`). The CLI path has no password gate — shell access to
  run `bin/osf` is already a higher privilege than the admin panel itself.
- Web flow: `GET /admin/admins/{id}/delete` — confirmation screen states
  deletion is permanent, shows the target's email, current-password field →
  `POST` (same path) on success redirects to `/admin/admins` with a flash.

## No-JS submission policy (fix/nojs-policy) — condensed; see HISTORY
- `public/embed/osf.js`: one static vanilla-ES2017 file, no deps/build, rich
  progressive-enhancement UX, never throws uncaught, degrades to native POST.
  The snippet's `<form action>` is the submit URL, so JS-off still POSTs
  directly; `SubmitHtmlPage` renders a self-contained success/error page.
- Migration 009 `forms.allow_nojs` (admin checkbox): on the HTML-negotiated
  path, `allow_nojs=0` (default) turns a missing/forged/too-young token into
  an honest `400 javascript_required`; `allow_nojs=1` skips the check only
  for a *missing* token. A filled honeypot always gets the generic success
  page either way. The JSON/fetch path is unaffected.

## Dev tooling + upgrade path (chore/dev-serve-and-migrate) — condensed
- `composer serve` → `public/dev-router.php` (PHP's built-in server 404s
  static-looking paths otherwise); `config.process-timeout: 0`. `GET /`
  redirects to `/admin/login` (installed) or `/install` (not).
- `bin/osf migrate`: explicit, human-run counterpart to the silent
  auto-migrate every `bin/osf` command already did at boot — checks
  `Paths::isInstalled()`, reports each newly-applied migration or "Already
  up to date.", idempotent. `MigrationRunner::pendingCount()` drives a
  non-dismissible red dashboard banner when the schema is behind.

## Mail-setup, installer, admin auth, Turnstile, mail relay — condensed; see HISTORY
- Installer: installed iff BOTH var/config.php and var/install.lock exist;
  CSRF wizard writes config atomically then the lock.
- Mail wizard (`/admin/mail`): SMTP write-back (atomic ConfigWriter,
  write-only password), test send, SPF/DKIM/DMARC checker, `bin/osf
  mail:status`.
- Admin auth: migrations 007/008 `admins`; argon2id, Base32/TOTP/recovery,
  session seam, Csrf, AuthService (rate limits, timeouts, TOTP gate); strict
  CSP, every screen works JS-off; forms/submissions CRUD, account/admins, 2FA.
- Turnstile: optional PER FORM, both-or-neither; fail-open; secret never
  exposed.
- Mail relay: `MessageBuilder` (From always the service address, Reply-To
  the submitter's valid email only); `DeliveryService`
  (sent/failed+backoff/dead at MAIL_MAX_ATTEMPTS + retryDue); `bin/osf
  mail:retry|mail:test`.

## Submission pipeline order (enforced + tested)
method/body size → field hygiene → form lookup by URL key → origin allowlist
→ per-IP then per-form rate limits → honeypot → token → Turnstile (optional)
→ email (syntax + MX/A) → store → delivery (terminal, always succeeds).
Locked by SubmitPipelineOrderTest. The token stage's outcome additionally
depends on `SubmitContext::prefersHtml` and `form.allow_nojs`; the stage
order itself is unchanged.

## What exists now
Everything through Increment 7 (embed artefact + no-JS fallback), plus
chore/dev-serve-and-migrate (dev router, root redirect, `bin/osf migrate`,
dashboard staleness banner) and this sprint's admin deletion (see "Admin
deletion" above: `AdminRepository::deleteAdmin`; `AdminsController::
deleteConfirm/delete`; `templates/admin/admin_delete_confirm.php`; the
Admins-screen Delete action; `bin/osf admin:delete`). Only hard Composer dep
remains phpmailer/phpmailer ^6.

## Known gaps / not built (by design)
- Synthetic monitoring (8), zip packaging/release layout + production
  `.htaccess` (front-controller rewrite + static cache headers) — later
  increments. Migration-on-update is solved for the manual case (`bin/osf
  migrate` + dashboard banner); still missing any AUTOMATIC trigger (e.g. a
  post-unzip hook) — out of scope until the release/packaging work.
- Password RESET by email, roles/permissions, audit log. (Account deletion
  itself is now built — see "Admin deletion" above; still no audit trail of
  who deleted whom.)
- File uploads / redirect success URLs (explicitly out of embed scope).
- Real MySQL live-test, NativeSession `$_SESSION`, real SMTP/DNS, and the DOM
  behaviour of osf.js stay un-unit-tested (network/globals/no DOM harness);
  osf.js is covered by `node --check` + the manual checklist.

## Open items
None outstanding. QUESTIONS.md Increment 7 #1 (no-JS delivery vs the token
stage) and #2 (osf.js size) were RESOLVED in fix/nojs-policy; the 6b
branch/merge-order flag was already RESOLVED (6a/6b/7 merged to main via PRs
#15/#16/#17). Nothing raised in chore/dev-serve-and-migrate or this sprint
(fix/admin-delete).

## Planned increment sequence (subject to revision)
0. Skeleton. 1. Schema. 2. Pipeline. 3. SMTP relay. 4. Turnstile. 5a. Admin
auth. 5b. Design system + CRUD. 5c. Account mgmt + 2FA lifecycle. 6a. Installer
engine. 6b. Email-setup wizard + installer polish. 7. Embed snippet + JS. — ALL
DONE. 8. Synthetic monitoring + alerting.
