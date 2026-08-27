# OpenSendForm — current state

Last updated: 2026-08-27 (fix/5d-polish-v3, Claude Code)

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
icons, responsive card-collapse tables) merged to main via PR #21.
fix/5d-polish (merged to main via PR #24) was two rounds of architect-directed
polish: a first pass (exact GitHub palette, header/tab-nav split, a spacing
token scale, button/badge/input consistency), then a second, LIVE-VERIFIED
pass ("5d polish v2") that re-corrected the dark palette against github.com's
actual computed styles, reshaped the header into two same-surface rows with
one hairline, added an orange active-tab token, swapped the standalone logout
button for a `<details>` account dropdown, and tightened spacing/control/
banner consistency. fix/5d-polish-v3 (this sprint, not yet merged) is a third
polish round: the header's two rows now sit on DIFFERENT surfaces (GitHub
alignment, not "same surface" as v2 had it), tabs gained icons, the Admins
Deactivate/Reactivate buttons became a toggle switch, Forms row buttons are
equal-width, and every remaining template was converted to the `.osf-field`
wrapper pattern — see "Design system" below. Suite green (455 tests). CI runs
tests + a package build/verify on every PR/push.

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

## Design system (5d + fix/5d-polish v1 + v2) — condensed; see HISTORY
- Single source of colour: `public/assets/tokens.css` defines the `--osf-*`
  contract (surfaces/text/borders/accent/status/focus/tab-active/shadow/
  spacing/type/shape) on `:root` (dark default) and `[data-theme="light"]`.
  **Dark palette values are LIVE-VERIFIED** (as of fix/5d-polish v2,
  2026-08-27) — captured directly from github.com's deployed computed
  styles, not the @primer/primitives package and not eyeballed like the
  first "5d polish" pass (whose values this superseded). **Deliberate
  exception: `--osf-success` stays GREEN** — Primer's own `--fgColor-success`
  alias currently resolves to a blue upstream, but green is what github.com's
  UI actually renders, so the live-verified green was kept over the
  (apparently mistaken) upstream alias; the docblock records this. Light
  palette was already correct from the first pass and is unchanged. Token
  NAMES unchanged throughout. `--osf-tab-active` (`#f78166`, both modes) is
  architect-supplied — GitHub UnderlineNav's active-tab orange, not derived
  from the surface/accent/status families. `data-palette` on `<html>`
  reserved; only `github` implemented. A PHPUnit grep test (DesignSystemTest)
  forbids any hardcoded colour in templates/ + `public/assets/*.css|js`
  (exempt: tokens.css, vendor/qrcode.js; embed/osf.js out of scope) — this is
  why the account-menu dropdown shadow is a token (`--osf-shadow-dropdown`)
  rather than an inline `rgba()`.
- **Spacing / `.osf-field` (STANDARD PATTERN, fix/5d-polish-v3):**
  `--osf-space-1`..`--osf-space-6` (4/8/12/16/24/32px) applied systematically
  across `admin.css` and templates. `.osf-field` is the single wrapper for
  "label + control + optional hint/error": `margin-bottom: space-4` between
  fields (`:last-child` zeroes it so a section's own bottom padding — `section`
  is `space-5` all round — provides the boundary gap instead of stacking);
  `.osf-field label { margin-top: 0 }` overrides the global label's
  `space-4` top margin so field rhythm is controlled by the wrapper, not the
  label. Inputs/selects/textareas share one rule: `space-2` vertical /
  `space-3` horizontal padding. **Every admin + installer template with a
  visible form control now uses this wrapper** — login, totp, totp_setup
  (all three branches), recovery_codes (the JS-revealed ack checkbox),
  account, admins (add-admin form), admin_delete_confirm, form_edit (every
  field, including checkboxes-in-fieldsets and the Turnstile pair), mail
  (SMTP settings, test-send, DKIM re-check row), submissions' filter-bar
  selects (`.osf-filter-bar .osf-field { margin: 0 }` neutralises the
  wrapper's vertical rhythm inside that horizontal bar), and every
  templates/install/ step (welcome has no controls; admin, database,
  finish do). `DesignSystemTest`/`AdminUiTest`/`AccountAdminsHttpTest`/
  `InstallerHttpTest` each carry a DOM-based regression check
  (`Tests\Support\AdminUiFieldWrapperAssertions`) that renders every screen
  and asserts every visible `<input>`/`<select>`/`<textarea>` (hidden inputs
  and the CSRF field exempt) has an `.osf-field` ancestor — no orphan
  controls can be reintroduced silently. `.osf-toolbar` + `.osf-filter-bar`
  still give the Submissions filter row its compact inline layout
  (status/form/Filter, space-2 gap) with "Retry all due now" separated
  alongside it. A `.osf-section-top` utility (`margin-top: space-6`) fixes
  sections that follow non-section content (e.g. a table) with no natural
  gap of their own — currently only the Admins "Add an admin" section.
- **Header surfaces (GitHub alignment, fix/5d-polish-v3 — supersedes v2's
  "same surface" reading):** `admin.css` rewritten bespoke (Pico removed):
  element defaults + the used component set, tokens only. Chrome is a
  COMPACT, TWO-ROW header on TWO DIFFERENT surfaces with NO divider between
  them. Row 1 (`.osf-header`, `--osf-bg-inset` — the near-black, live-verified
  against github.com's own header): brand left; right-aligned Docs link,
  theme toggle, account dropdown. Row 2 (`.osf-tabnav`/`.osf-tab-link`,
  `--osf-bg` — the page background, distinct from row 1): GitHub-UnderlineNav-
  style tabs for the five destinations (Dashboard/Forms/Submissions/Email/
  Admins), each preceded by its Lucide icon (`layout-dashboard`/`file-text`/
  `inbox`/`mail`/`users`) at text size via `.osf-icon`'s `width:1em;
  height:1em`, `currentColor`-driven so icon + label share the same
  active/inactive/hover colour with no extra CSS; a single hairline
  `--osf-border` sits under this row only — the one divider in the two-row
  header. Active tab = `--osf-text` on a 2px `--osf-tab-active` underline,
  inactive = `--osf-text-muted`, hover underline = `--osf-border`. Neither
  bar is width-constrained — `.osf-header`/`.osf-tabnav` span the full
  viewport; only their `-inner container` children align to the content
  column. NO sidebar/tree/search/breadcrumbs. "Account" is not a nav item —
  it's a menu entry (see below). Both rows wrap on narrow viewports.
- **Account menu**: the admin name is a `<details class="osf-account-menu">
  <summary>` dropdown trigger (native, no JS, CSP-safe — GitHub's own
  pattern) with a vendored `chevron-down` Lucide icon (rotates on `[open]`
  via CSS). The panel (`--osf-bg-raised`/`--osf-border`/`--osf-radius`/
  `--osf-shadow-dropdown`, right-aligned) holds two `.osf-account-item`
  entries: "Your account" (→ `/admin/account`) and the existing CSRF logout
  `<form>` restyled as a danger-toned item (`.osf-account-item--danger`).
  There is no standalone logout button anywhere else. No JS added for
  outside-click dismissal (native `<details>` behaviour accepted as-is).
- One shared button rule (`inline-flex`, centred icon+label, fixed gap,
  uniform space-2/space-4 padding); a `.osf-btn-sm` small variant
  (space-1/space-3, 0.85rem) is applied to every action button inside a
  table row (Edit, Disable/Enable, Delete, Retry) so same-context row
  actions match in height. A `.osf-btn-equal` add-on (`min-width: 5.5rem;
  justify-content: center`) is applied to Forms-list Edit + Disable/Enable
  so differing label lengths don't stagger that column across rows.
  `.osf-copy` (Copy/Re-check) keeps its own, already-smaller compact tier —
  a deliberately distinct utility size, not folded into `.osf-btn-sm`.
  Badges (one `.osf-badge` size) and inputs (one shared rule) remain
  consistent. Dismissible banners (`.osf-nudge`) never wrap: the message
  grows (`flex: 1 1 auto`) and the dismiss control (`flex: none`, icon-only
  `x` glyph, `.osf-btn-sm`) stays pinned on the same row.
- **Admins status switch (fix/5d-polish-v3 — replaces the Deactivate/
  Reactivate buttons):** the Admins-page Status column is now a `.osf-switch`
  track+thumb control — a plain CSRF POST `<button type="submit">` (no JS
  required), `role="switch"` + `aria-pressed` + an `aria-label` naming the
  action ("Deactivate x@example.com" / "Reactivate x@example.com"),
  success-toned (`--osf-success`) when active, muted (`--osf-text-subtle`
  track) when inactive. Where the action is refused server-side (the last
  active admin, including self-as-last), the switch renders as a disabled
  `<button type="button">` (no form, no action URL in the markup) with a
  `title` explaining why. The Actions column now holds ONLY Delete (or an
  em-dash placeholder) — the guard logic (`$canDeactivate`/`$canDelete` in
  admins.php) and the underlying endpoints/guards are unchanged, only the
  buttons' presentation moved.
- Theme: default dark; toggle cycles Dark/Light/Auto; persisted in
  localStorage `osf-theme`. `public/assets/theme-init.js` (external, first in
  `<head>`, blocking) sets `data-theme` (resolved) + `data-theme-mode` (choice)
  pre-paint → no flash. Toggle UI logic in the deferred admin.js. Icons from a
  vendored Lucide subset (ISC) `src/Admin/icons.php` (helper `icon()`, loaded
  by TemplateRenderer), currentColor-driven — fixes the old invisible-in-light
  toggle glyph. fix/5d-polish-v3 added the five tab icons (`layout-dashboard`,
  `file-text`, `inbox`, `mail`, `users`) to the vendored set.
- Responsive tables collapse to label+value cards under 640px via `data-label`
  cells (no horizontal scroll); submission/dashboard last-error cells are
  no-JS `<details>` expanders. Installer shares the design system (same
  tokens/CSS/theme-init, header restructured to the same brand/actions shape,
  no tab bar, no account menu). Housekeeping: zip PHP ext added to the
  devcontainer Dockerfile (next rebuild). SCOPE FENCE (fix/5d-polish v1/v2):
  embed osf.js + public API untouched; the logout control's only
  behavioural change is its new location (inside the account menu), same
  CSRF POST. SCOPE FENCE (fix/5d-polish-v3): admin + installer
  templates/CSS only; embed untouched; the only behavioural change is the
  Admins Deactivate/Reactivate buttons' presentation becoming the status
  switch — same endpoints, same guards.

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
