# OpenSendForm — current state

Last updated: 2026-08-27 (fix/5d-polish, Claude Code)

## Status
The service is end-to-end: a versioned v1 API drives an ordered
validation/abuse pipeline; passing submissions are stored and relayed by
authenticated SMTP (in-request send + operator retry cron). Full admin panel
(auth, 2FA, forms/submissions CRUD, mail-setup wizard, browser installer),
a client-site embed artefact with a no-JS fallback, dev-server tooling, an
explicit migration command, guarded admin deletion and release packaging
(v0.1.0 zip + `.htaccess` set + `bin/osf version`) are all built and merged to
main — see the condensed sections below and HISTORY.md for full detail.
Increment 5d (design-system overhaul: Pico.css retired, token contract +
bespoke `admin.css`, top-nav header, Dark/Light/Auto theme, vendored Lucide
icons, responsive card-collapse tables) merged to main via PR #21. This patch
(fix/5d-polish) is architect-directed polish on top of it: exact GitHub
palette values, a header/tab-nav split, a spacing token scale applied
systematically, and button/badge/input control consistency — see "Design
system" below. Suite green (444 tests). CI runs tests + a package
build/verify on every PR/push.

## Product definition
Free, open-source, self-hostable form-to-email service for shared cPanel/PHP
hosting. One installation serves many websites via an embedded snippet + JS
posting to a central endpoint. Validated, abuse-filtered, relayed by
authenticated SMTP to the site owner.

## Decisions locked
- Name: OpenSendForm. Stack: PHP 8.1+ / Slim 4 / Composer / PHPMailer /
  SQLite default (MySQL optional via PDO). Server-rendered admin UI on a
  bespoke token-driven stylesheet (Pico.css retired in 5d); no JS framework,
  no build step; JS is enhancement-only.
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

## Design system (5d + fix/5d-polish) — condensed; see HISTORY
- Single source of colour: `public/assets/tokens.css` defines the `--osf-*`
  contract (surfaces/text/borders/accent/status/focus/spacing/type/shape) on
  `:root` (dark default) and `[data-theme="light"]`. **Palette values as of
  fix/5d-polish are an architect-supplied reference set captured directly
  from github.com's deployed dark/light UI ("GitHub classic as deployed"),
  not the @primer/primitives package** — Primer's current published palette
  has since diverged from what github.com itself renders, so the file now
  tracks the deployed site (docblock records this explicitly; token NAMES
  unchanged from the original 5d contract). `data-palette` on `<html>`
  reserved; only `github` implemented. Contract is shared verbatim with the
  docs site. A PHPUnit grep test (DesignSystemTest) forbids any hardcoded
  colour in templates/ + `public/assets/*.css|js` (exempt: tokens.css,
  vendor/qrcode.js; embed/osf.js out of scope).
- Spacing scale: `--osf-space-1`..`--osf-space-6` (4/8/12/16/24/32px),
  applied systematically across `admin.css` (table cells, labels, section/
  card padding, button/input padding, the page `<h1>`, and a `.osf-actions`
  flex wrapper for button groups) in place of ad-hoc rem values.
- `admin.css` rewritten bespoke (Pico removed): element defaults + the used
  component set, tokens only. Chrome is a header bar (`.osf-header`: brand +
  right-aligned Docs link/theme toggle/admin-name/logout) with a separate
  GitHub-UnderlineNav-style tab bar (`.osf-tabnav`/`.osf-tab-link`) beneath it
  for the five destinations (Dashboard/Forms/Submissions/Email/Admins) — NO
  sidebar/tree/search/breadcrumbs. "Account" is not a nav item; it lives only
  on the admin-name header link. Both rows wrap on narrow viewports.
- One shared button rule (`inline-flex`, centred icon+label, fixed gap,
  uniform space-2/space-4 padding) — fixes a prior bug where inline-form
  buttons (Reactivate/Deactivate) rendered smaller than plain-link buttons
  (Delete/Edit/Disable) due to a now-removed `.osf-inline-form button`
  override. `.osf-copy` (Copy/Re-check) keeps a deliberately smaller compact
  tier. Badges (one `.osf-badge` size, colour-only variants) and inputs
  (one shared rule) were already consistent.
- Theme: default dark; toggle cycles Dark/Light/Auto; persisted in
  localStorage `osf-theme`. `public/assets/theme-init.js` (external, first in
  `<head>`, blocking) sets `data-theme` (resolved) + `data-theme-mode` (choice)
  pre-paint → no flash. Toggle UI logic in the deferred admin.js. Icons from a
  vendored Lucide subset (ISC) `src/Admin/icons.php` (helper `icon()`, loaded
  by TemplateRenderer), currentColor-driven — fixes the old invisible-in-light
  toggle glyph.
- Responsive tables collapse to label+value cards under 640px via `data-label`
  cells (no horizontal scroll); submission/dashboard last-error cells are
  no-JS `<details>` expanders. Installer shares the design system (same
  tokens/CSS/theme-init, header restructured to the same brand/actions shape,
  no tab bar). Housekeeping: zip PHP ext added to the devcontainer Dockerfile
  (next rebuild). SCOPE FENCE: embed osf.js + public API untouched.

## Admin deletion (fix/admin-delete) — condensed; see HISTORY
- Admins can be hard-deleted (`AdminRepository::deleteAdmin`, prepared
  `DELETE`); deactivation stays as the reversible alternative. Three
  server-enforced guards on the web POST and `bin/osf admin:delete`: no
  self-delete; the last ACTIVE admin can't be deleted; the web confirmation
  re-verifies the acting admin's CURRENT password (CLI has no password gate).
  Web flow: `GET/POST /admin/admins/{id}/delete`.

## No-JS submission policy (fix/nojs-policy) — condensed; see HISTORY
- `public/embed/osf.js`: one static vanilla-ES2017 file, no deps/build,
  degrades to native POST (the snippet's `<form action>` is the submit URL;
  `SubmitHtmlPage` renders a self-contained success/error page).
- Migration 009 `forms.allow_nojs` (admin checkbox): on the HTML path,
  `allow_nojs=0` (default) turns a missing/forged/too-young token into an
  honest `400 javascript_required`; `allow_nojs=1` skips the check only for a
  *missing* token. A filled honeypot always gets the generic success page. The
  JSON/fetch path is unaffected.

## Dev tooling + upgrade path (chore/dev-serve-and-migrate) — condensed
- `composer serve` → `public/dev-router.php`; `GET /` redirects to
  `/admin/login` (installed) or `/install` (not).
- `bin/osf migrate`: explicit human-run counterpart to the boot auto-migrate;
  idempotent. `MigrationRunner::pendingCount()` drives a non-dismissible red
  dashboard banner when the schema is behind.

## Packaging & releases (Increment 8) — condensed; see HISTORY
- `bin/build-release.php` (git archive HEAD → `composer install --no-dev` →
  prune exclusions → INSTALL.txt + `.htaccess` set → zip) produces
  `dist/opensendform-v{VERSION}.zip` (one `opensendform/` folder);
  `bin/verify-release.php` asserts exclusions-absent/required-present/
  autoloader-smoke/version-match. Shared `bin/release_lib.php` prefers
  ZipArchive, falls back to `zip`/`unzip` CLIs. `src/Version.php` (0.1.0) is
  the sole version source.
- `.htaccess` set: deny-all in var/migrations/bin/templates/src/vendor;
  `public/.htaccess` = front-controller + gzip + caching + `-Indexes`.
- Upgrade: replace all files EXCEPT `var/`, then `bin/osf migrate` (or the
  dashboard banner). `bin/osf version` prints app+schema version + pending
  count. CI `package` job builds+verifies+uploads the zip.

## Mail-setup, installer, admin auth, Turnstile, mail relay — condensed; see HISTORY
- Installer: installed iff BOTH var/config.php and var/install.lock exist; CSRF
  wizard writes config atomically then the lock.
- Mail wizard (`/admin/mail`): SMTP write-back (write-only password), test
  send, SPF/DKIM/DMARC checker, `bin/osf mail:status`.
- Admin auth: migrations 007/008 `admins`; argon2id, Base32/TOTP/recovery,
  Csrf, AuthService (rate limits, timeouts, TOTP gate); strict CSP, every
  screen works JS-off; forms/submissions CRUD, account/admins, 2FA.
- Turnstile: optional PER FORM, both-or-neither; fail-open; secret never shown.
- Mail relay: `MessageBuilder` (From always the service address, Reply-To the
  submitter's valid email only); `DeliveryService` (sent/failed+backoff/dead at
  MAIL_MAX_ATTEMPTS + retryDue); `bin/osf mail:retry|mail:test`.

## Submission pipeline order (enforced + tested)
method/body size → field hygiene → form lookup by URL key → origin allowlist
→ per-IP then per-form rate limits → honeypot → token → Turnstile (optional)
→ email (syntax + MX/A) → store → delivery (terminal, always succeeds).
Locked by SubmitPipelineOrderTest. The token stage's outcome additionally
depends on `SubmitContext::prefersHtml` and `form.allow_nojs`; the stage
order itself is unchanged.

## What exists now
Everything through Increment 8 (embed + no-JS fallback, dev tooling, admin
deletion, packaging/v0.1.0), plus this sprint's design system
(`public/assets/tokens.css` + rewritten `admin.css` + `theme-init.js`;
`src/Admin/icons.php`; restyled `_nav.php`; card-collapse tables). Pico.css +
the old `theme.js` were removed. Production Composer deps remain
phpmailer/phpmailer ^6, slim/slim ^4, slim/psr7, php-di/php-di.

## Known gaps / not built (by design)
- Synthetic monitoring + alerting — the remaining planned increment.
- Migration-on-update is solved for the manual case (`bin/osf migrate` +
  dashboard banner + `bin/osf version`); there is still no AUTOMATIC trigger
  (e.g. a post-unzip hook), and the upgrade procedure is documented rather than
  scripted. GitHub Releases automation, Softaculous, and code signing were
  explicitly out of scope for Increment 8 (manual zip attach for now).
- The dev container's PHP had no zip extension; the build/verify scripts fall
  back to the `zip`/`unzip` CLIs (CI adds the ext). 5d adds `zip` to the
  devcontainer Dockerfile, so a container REBUILD closes this gap locally too;
  the CLI fallback stays as belt-and-braces. See QUESTIONS.md Inc 8 #1.
- Password RESET by email, roles/permissions, audit log. (Account deletion
  itself is now built — see "Admin deletion" above; still no audit trail of
  who deleted whom.)
- File uploads / redirect success URLs (explicitly out of embed scope).
- Real MySQL live-test, NativeSession `$_SESSION`, real SMTP/DNS, and the DOM
  behaviour of osf.js stay un-unit-tested (network/globals/no DOM harness);
  osf.js is covered by `node --check` + the manual checklist.

## Open items
None blocking. QUESTIONS.md Increment 8 #1 records a DEVIATION (no zip PHP
extension in the container → ZipArchive-or-CLI fallback), resolved without
architect input; noted only so the base-image gap is on record. Earlier
questions (Inc 7 #1/#2, the 6b merge-order flag) were all RESOLVED.

## Planned increment sequence (subject to revision)
0. Skeleton. 1. Schema. 2. Pipeline. 3. SMTP relay. 4. Turnstile. 5a. Admin
auth. 5b. Design system + CRUD. 5c. Account mgmt + 2FA lifecycle. 5d. Design
system overhaul (token contract + Starlight/GitHub parity). 6a. Installer
engine. 6b. Email-setup wizard + installer polish. 7. Embed snippet + JS.
8. Packaging: release zip + versioning + upgrade path + install docs. — ALL
DONE. 9. Synthetic monitoring + alerting (renumbered from 8).
