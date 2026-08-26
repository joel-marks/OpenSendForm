# OpenSendForm — current state

Last updated: 2026-08-26 (feature/increment-7-embed, Claude Code)

## Status
The service is end-to-end: a versioned v1 API drives an ordered
validation/abuse pipeline; passing submissions are stored and relayed by
authenticated SMTP (in-request send + operator retry cron). Increment 7 adds the
CLIENT-SITE ARTEFACT: one static embed JS (`public/embed/osf.js`) any website
pastes in, a no-JS HTML fallback on the submit endpoint, cache-headed embed
serving, and an admin "Embed code" snippet panel. Prior increments: 4 optional
per-form Turnstile; 5a/5b/5c the ADMIN stack (argon2id auth, TOTP 2FA + recovery
codes, Pico.css, forms/submissions CRUD, account/admins mgmt); 6a the BROWSER
INSTALLER engine; 6b the MAIL-SETUP wizard (SMTP write-back, test send,
SPF/DKIM/DMARC checker). Suite green (409 tests). CI runs it on every PR/push.
6a and 6b are now merged to main (PRs #15/#16), so this branch (off main) builds
on the full stack.

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
  413/429. Increment 7 adds content negotiation: a client that prefers
  text/html (a native browser form POST) gets an HTML page instead — the JSON
  shape is unchanged for fetch/API callers.
- Bot-facing checks fail SILENTLY (filled honeypot; missing/forged/too-young
  token → fake success, nothing stored); an authentic but EXPIRED token returns
  an honest `400 token_expired`.
- Content always stored as the in-flight delivery payload; `store_content`
  means "retain content after successful delivery".
- Config precedence: defaults < var/config.php < environment (env always wins).

## Embed artefact + no-JS fallback (Increment 7) — this sprint
- `public/embed/osf.js`: one static vanilla-ES2017 file, no deps/build. Each
  `form[data-osf-key]` initialises independently. Reads `data-osf-key` +
  `data-osf-url` (installation base); fetches/holds a token; injects an
  `_osf_hp` honeypot; submits over `fetch` (urlencoded, `Accept:
  application/json`) with `_osf_token`/`_osf_hp`/`_osf_cf`.
- Rich UX: submitting spinner; success overlay dialog (focus-trapped, Esc/OK,
  focus returned) then a "Send another" submitted panel; inline field error for
  `invalid_email`/`email_domain_invalid` else a form-level strip (input values
  preserved); invisible `token_expired` refresh-and-retry-once; Turnstile loaded
  once + rendered above submit when the token response carries a sitekey, reset
  on failure. Namespaced injected `<style>` (`.osf-`, CSS-variable themable),
  `prefers-reduced-motion`, aria-live. Events `osf:submit`/`:success`/`:error`;
  `data-osf-ui="none"` suppresses UI but keeps events. Never throws uncaught —
  any unexpected path degrades to the native POST.
- Progressive enhancement: the snippet's `<form action>` is the submit URL, so
  with JS off the browser POSTs directly. `src/Http/SubmitHtmlPage.php` renders
  a self-contained success/error HTML page (inline styles, back-link from a
  validated http(s) Referer). `Routes::submit` content-negotiates on Accept.
- Serving: `GET /embed/osf.js` → long immutable `Cache-Control` (snippet busts
  it with `?v=`); front-controller fallback/header seam (Apache serves the
  static file directly in prod). `GET /embed/manual.html` → dev-only manual
  checklist (`tests/embed-manual.html`), 404 elsewhere.
- Admin: form-edit "Embed code" panel (existing forms only) shows the
  ready-filled copy-paste snippet (form + versioned script tag) with a
  `[data-copy]` button; `FormsController` derives the base URL from the request.

## Mail-setup wizard + installer (6a/6b) — condensed; see HISTORY
- 6a: installed iff BOTH var/config.php and var/install.lock exist; CSRF wizard
  writes config atomically then the lock; `Config::fromFile/load`, `DB_USER/
  DB_PASS`, `generateSecret()`, `OSF_BASE_DIR`; written config sets
  MAIL_ENABLED=0. 6b: `/admin/mail` SMTP write-back (atomic ConfigWriter,
  write-only password, env-shadow notice), test send, `Mail\Deliverability
  Checker` over an injectable `DnsResolver` (SPF/DKIM/DMARC green/amber + exact
  record), installer done→mail handoff + dashboard nudge, `bin/osf mail:status`.

## Admin / Turnstile / Mail relay — condensed; see HISTORY
- Admin (5a–c): migrations 007/008 `admins`; argon2id, Base32/TOTP/recovery,
  session seam, Csrf, AuthService (rate limits, timeouts, TOTP gate); strict CSP,
  every screen works JS-off; forms/submissions CRUD, account/admins, 2FA.
- Turnstile (4): optional PER FORM, both-or-neither; verifier iface + curl/fake;
  fail-open; secret never exposed.
- Mail relay (3): `MessageBuilder` (From always the service address, Reply-To the
  submitter's valid email only); `DeliveryService` (sent/failed+backoff/dead at
  MAIL_MAX_ATTEMPTS + retryDue); `bin/osf mail:retry|mail:test`.

## Submission pipeline order (enforced + tested)
method/body size → field hygiene → form lookup by URL key → origin allowlist
→ per-IP then per-form rate limits → honeypot → token → Turnstile (optional)
→ email (syntax + MX/A) → store → delivery (terminal, always succeeds).
Locked by SubmitPipelineOrderTest.

## What exists now
0–6b as before, plus Increment 7: `public/embed/osf.js`, `src/Http/
SubmitHtmlPage.php`; `Routes` content negotiation + `/embed/osf.js` and
`/embed/manual.html` routes; `templates/admin/form_edit.php` embed panel +
`FormsController` base-URL derivation; `tests/embed-manual.html`; tests
`tests/Http/{SubmitHtmlResponseTest,EmbedAssetTest}`, `tests/Admin/
EmbedPanelTest`. Only hard Composer dep remains phpmailer/phpmailer ^6.

## Known gaps / not built (by design)
- Synthetic monitoring (8), zip packaging/release layout + production `.htaccess`
  (front-controller rewrite + static cache headers), upgrade/migration-on-update
  — later increments.
- No-JS SUBMISSION DELIVERY: the no-JS HTML page renders, but a tokenless no-JS
  post is silently discarded by the locked token stage (no email) — pending an
  architect ruling (QUESTIONS.md #1).
- Password RESET by email, roles/permissions, account deletion, audit log.
- File uploads / redirect success URLs (explicitly out of embed scope).
- Real MySQL live-test, NativeSession `$_SESSION`, real SMTP/DNS, and the DOM
  behaviour of osf.js stay un-unit-tested (network/globals/no DOM harness);
  osf.js is covered by `node --check` + the manual checklist.

## Open items
- QUESTIONS.md Increment 7 #1 (no-JS delivery vs the token stage — SECURITY,
  needs a ruling) and #2 (osf.js 15.4 KB over the 12 KB brief target — infeasible
  as readable source; ratify).
- The 6b branch/merge-order flag (QUESTIONS.md) is now RESOLVED — 6a/6b merged to
  main via PRs #15/#16.

## Planned increment sequence (subject to revision)
0. Skeleton. 1. Schema. 2. Pipeline. 3. SMTP relay. 4. Turnstile. 5a. Admin
auth. 5b. Design system + CRUD. 5c. Account mgmt + 2FA lifecycle. 6a. Installer
engine. 6b. Email-setup wizard + installer polish. 7. Embed snippet + JS. — ALL
DONE. 8. Synthetic monitoring + alerting.
