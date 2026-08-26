# OpenSendForm — current state

Last updated: 2026-08-26 (fix/nojs-policy, Claude Code)

## Status
The service is end-to-end: a versioned v1 API drives an ordered
validation/abuse pipeline; passing submissions are stored and relayed by
authenticated SMTP (in-request send + operator retry cron). Increment 7 added
the CLIENT-SITE ARTEFACT: one static embed JS (`public/embed/osf.js`) any
website pastes in, a no-JS HTML fallback on the submit endpoint, cache-headed
embed serving, and an admin "Embed code" snippet panel. This sprint
(fix/nojs-policy) resolved the increment's open no-JS delivery question with a
per-form `allow_nojs` toggle — see "No-JS submission policy" below. Prior
increments: 4 optional per-form Turnstile; 5a/5b/5c the ADMIN stack (argon2id
auth, TOTP 2FA + recovery codes, Pico.css, forms/submissions CRUD,
account/admins mgmt); 6a the BROWSER INSTALLER engine; 6b the MAIL-SETUP
wizard (SMTP write-back, test send, SPF/DKIM/DMARC checker). Suite green (404
tests). CI runs it on every PR/push. Increments 6a/6b/7 are merged to main
(PRs #15/#16/#17); this branch builds on the full stack.

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
  an honest `400 token_expired`. Deliberate exception: on the HTML-negotiated
  (no-JS) path, a form with `allow_nojs=0` (default) instead returns an honest
  `400 javascript_required` for a missing/forged/too-young token — see "No-JS
  submission policy" below.
- Content always stored as the in-flight delivery payload; `store_content`
  means "retain content after successful delivery".
- Config precedence: defaults < var/config.php < environment (env always wins).

## Embed artefact + no-JS fallback (Increment 7, policy refined by
## fix/nojs-policy) — condensed
- `public/embed/osf.js`: one static vanilla-ES2017 file, no deps/build. Each
  `form[data-osf-key]` initialises independently: fetches/holds a token,
  reuses an existing `_osf_hp` honeypot input or injects one, submits over
  `fetch` (`_osf_token`/`_osf_hp`/`_osf_cf`). Rich UX (spinner, focus-trapped
  success dialog, "Send another", inline/form-level errors, invisible
  `token_expired` retry, Turnstile render/reset, themeable injected CSS,
  `prefers-reduced-motion`, aria-live, `osf:submit/:success/:error` events,
  `data-osf-ui="none"`); never throws uncaught, degrades to native POST.
- Progressive enhancement: the snippet's `<form action>` is the submit URL
  (with a static hidden `_osf_hp` honeypot field), so JS-off still POSTs
  directly. `src/Http/SubmitHtmlPage.php` renders a self-contained
  success/error HTML page; `Routes::submit` content-negotiates on Accept into
  `SubmitContext::prefersHtml`, shared with `TokenStage`'s no-JS policy below.
- Serving: `GET /embed/osf.js` long-immutable-cached (`?v=` cache-bust);
  `GET /embed/manual.html` dev-only checklist. Admin form-edit "Embed code"
  panel shows the ready-filled snippet + `[data-copy]` button.
- **No-JS submission policy** (resolves QUESTIONS.md Increment 7 #1):
  migration 009 `forms.allow_nojs INTEGER NOT NULL DEFAULT 0` (admin checkbox
  "Allow submissions without JavaScript"; `bin/osf form:list` shows
  `[allow_nojs]`). On the HTML-negotiated path: `allow_nojs=0` (default) turns
  a missing/forged/too-young token into an honest `400 javascript_required`
  page, nothing stored — the one other exception to "bot checks fail
  silently" (see above); `allow_nojs=1` skips the token check only for a
  *missing* token (stores + delivers), a present-but-invalid one still falls
  through to the silent discard; all other stages apply unchanged. A filled
  honeypot always gets the generic success page (never the honest error) and
  is always discarded either way. The JSON/fetch path (what the embed JS
  sends) is unaffected by any of this.

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
Locked by SubmitPipelineOrderTest. The token stage's outcome now additionally
depends on `SubmitContext::prefersHtml` and `form.allow_nojs` — see "No-JS
submission policy" above; the stage order itself is unchanged.

## What exists now
0–6b as before, plus Increment 7: `public/embed/osf.js`, `src/Http/
SubmitHtmlPage.php`; `Routes` content negotiation + `/embed/osf.js` and
`/embed/manual.html` routes; `templates/admin/form_edit.php` embed panel +
`FormsController` base-URL derivation; `tests/embed-manual.html`; tests
`tests/Http/{SubmitHtmlResponseTest,EmbedAssetTest}`, `tests/Admin/
EmbedPanelTest`. Plus this sprint: migration 009 (`forms.allow_nojs`); the
`allow_nojs` checkbox on form-edit; `SubmitContext::prefersHtml`; the
`TokenStage` no-JS policy; the snippet's static `_osf_hp` honeypot field.
Only hard Composer dep remains phpmailer/phpmailer ^6.

## Known gaps / not built (by design)
- Synthetic monitoring (8), zip packaging/release layout + production `.htaccess`
  (front-controller rewrite + static cache headers), upgrade/migration-on-update
  — later increments.
- Password RESET by email, roles/permissions, account deletion, audit log.
- File uploads / redirect success URLs (explicitly out of embed scope).
- Real MySQL live-test, NativeSession `$_SESSION`, real SMTP/DNS, and the DOM
  behaviour of osf.js stay un-unit-tested (network/globals/no DOM harness);
  osf.js is covered by `node --check` + the manual checklist.

## Open items
None outstanding. QUESTIONS.md Increment 7 #1 (no-JS delivery vs the token
stage) and #2 (osf.js size) are both RESOLVED this sprint (fix/nojs-policy);
the 6b branch/merge-order flag was already RESOLVED (6a/6b/7 merged to main
via PRs #15/#16/#17).

## Planned increment sequence (subject to revision)
0. Skeleton. 1. Schema. 2. Pipeline. 3. SMTP relay. 4. Turnstile. 5a. Admin
auth. 5b. Design system + CRUD. 5c. Account mgmt + 2FA lifecycle. 6a. Installer
engine. 6b. Email-setup wizard + installer polish. 7. Embed snippet + JS. — ALL
DONE. 8. Synthetic monitoring + alerting.
