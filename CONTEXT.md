# OpenSendForm — current state

Last updated: 2026-09-03 (fix/tabnav-grey-and-stat-tones, Claude Code)

## Status
The service is end-to-end: a versioned v1 API drives an ordered
validation/abuse pipeline; passing submissions are stored and relayed by
authenticated SMTP (in-request send + operator retry cron). Full admin panel
(auth, 2FA, forms/submissions CRUD, mail-setup wizard, browser installer), a
client-site embed artefact with a no-JS fallback, dev-server tooling, an
explicit migration command, guarded admin deletion and release packaging
(v0.1.0 zip + `.htaccess` set + `bin/osf version`) are all built and merged to
main. The design system (increment 5d + fix/5d-polish v1/v2/v3 + the
header-surface follow-ups) is a bespoke `--osf-*` token contract with a
two-row GitHub-aligned header, Dark/Light/Auto theme, vendored Lucide icons and
responsive card-collapse tables. Suite green (466 tests). CI runs tests + a
package build/verify on every PR/push.

## Product definition
Free, open-source, self-hostable form-to-email service for shared cPanel/PHP
hosting. One installation serves many websites via an embedded snippet + JS
posting to a central endpoint. Validated, abuse-filtered, relayed by
authenticated SMTP to the site owner.

## Decisions locked
- Name: OpenSendForm. Stack: PHP 8.1+ / Slim 4 / Composer / PHPMailer /
  SQLite default (MySQL optional via PDO). Server-rendered admin UI on a
  bespoke token-driven stylesheet (Pico.css retired in 5d); no JS framework,
  no build step; JS is enhancement-only. No Node/Docker in production.
- All SQL portable across sqlite + mysql; timestamps stored as UTC `Y-m-d
  H:i:s` TEXT. Form keys are PUBLIC identifiers, stored plain.
- Response contract (FROZEN): JSON only by default. Success `{"ok":true}`;
  failure `{"ok":false,"error":{"code","message"}}`. HTTP 200/400/403/405/
  413/429. A client that prefers text/html (a native browser form POST) gets
  an HTML page instead — the JSON shape is unchanged for fetch/API callers.
- Bot-facing checks fail SILENTLY (filled honeypot; missing/forged/too-young
  token → fake success, nothing stored); an authentic but EXPIRED token
  returns an honest `400 token_expired`. Exception: on the HTML-negotiated
  (no-JS) path, a form with `allow_nojs=0` (default) returns an honest
  `400 javascript_required` for a missing/forged/too-young token.
- Content always stored as the in-flight delivery payload; `store_content`
  means "retain content after successful delivery".
- Config precedence: defaults < var/config.php < environment (env always wins).

## Design system (condensed — see HISTORY for the full evolution)
- Single source of colour: `public/assets/tokens.css` — the `--osf-*` contract
  on `:root` (dark default) and `[data-theme="light"]`. Dark palette is
  LIVE-VERIFIED against github.com (deliberate exception: `--osf-success` stays
  green). `--osf-tab-active` (`#f78166`, both modes) is architect-supplied.
  `DesignSystemTest` forbids any hardcoded colour outside tokens.css
  (+ vendor/qrcode.js; embed/osf.js out of scope). Token VALUES were frozen
  this sprint (out of scope).
- Spacing: `--osf-space-1..6` (4/8/12/16/24/32). `.osf-field` is the single
  label+control+hint wrapper across every admin + installer form; a DOM
  regression check (`AdminUiFieldWrapperAssertions`) asserts no visible control
  lacks an `.osf-field` ancestor. `.container` is layout-only by rule
  (max-width/padding/margin, never a `background`), enforced by DesignSystemTest.
- **Header (two rows, DIFFERENT surfaces, no divider between them):** row 1
  `.osf-header` = `--osf-bg-inset` (near-black); row 2 `.osf-tabnav` = `--osf-bg`
  (the page background) with a single hairline `--osf-border` under it only.
  Inner wrappers (`.osf-*-inner.container`) and `.osf-tab-link` are pinned
  `background: transparent`. Tabs are GitHub-UnderlineNav style with Lucide
  icons (currentColor); active = `--osf-text` on a 2px `--osf-tab-active`
  underline. Neither bar is width-constrained; the `-inner` children align to
  the content column. **VERIFIED this sprint (real Firefox, both themes):** the
  painted pixels ARE the correct token surfaces — row 1 `#010409`, row 2
  `#0d1117` (dark); `#f6f8fa`/`#ffffff` (light) — no grey `--osf-bg-raised`
  anywhere. See "Header-grey diagnostic" below.
- **Versioned asset URLs (structural cache-busting, this sprint):** every
  `<link>`/`<script>` under `/assets/` emitted by the admin + installer
  layouts carries `?v=<Version::STRING>` via one shared helper
  `OpenSendForm\Admin\asset()` (helpers.php) — no hand-typed version strings.
  Covers theme-init.js, tokens.css, admin.css, admin.js, install.js and the
  extraScripts path (qrcode.js). Embed assets (public/embed/*) are OUT of
  scope. This is the structural cure for "I still see old styling after an
  upgrade / hard reload"; a released CSS/JS change can never be served stale.
- **Dashboard stat tones (restyled this sprint — supersedes PR #27's heavier
  colourisation):** the four cards use ONLY the `-subtle` token family — a
  quiet background tint + a thin left border in the same subtle colour, no
  saturated fills, no coloured numerals. The value→tone mapping is computed in
  PHP (`statCardToneClass()`), never JS: value 0 → info/blue (any stat);
  non-zero → success, EXCEPT failure-measuring stats (Failed, Dead) → danger.
- Account menu: `<details>/<summary>` dropdown (native, no JS, CSP-safe) with a
  rotating chevron; holds "Your account" + the CSRF logout (danger-toned). No
  standalone logout button elsewhere.
- Buttons: one shared rule; `.osf-btn-sm` for row actions, `.osf-btn-equal`
  for equal-width Forms-list actions, `.osf-copy` its own compact tier. One
  badge size, one input rule. Admins status column is an accessible
  `.osf-switch` (CSRF POST, `role="switch"`), guarded server-side (last-active
  admin can't be deactivated).
- Theme: default dark; toggle cycles Dark/Light/Auto; persisted in
  localStorage `osf-theme`. `theme-init.js` (blocking, first in `<head>`) sets
  `data-theme` + `data-theme-mode` pre-paint (no flash). Icons from a vendored
  Lucide subset (`src/Admin/icons.php`), currentColor-driven.
- Responsive tables collapse to label+value cards under 640px; last-error cells
  are no-JS `<details>` expanders. Installer shares the design system.

## Header-grey diagnostic (fix/tabnav-grey-and-stat-tones) — VERDICT
The operator kept reporting the tab row rendering GREY/raised in Firefox on
macOS (dark) after PRs #26 and #27, despite headless Chromium checks and CSS
review declaring the rules correct. This sprint ran a REAL Firefox
(Playwright) painted-pixel diagnostic: logged-in `/admin`, both themes,
screenshot + canvas readback sampled at multiple points inside each row away
from text/borders, compared to tokens resolved at runtime; plus a
getComputedStyle dump and an `elementsFromPoint` walk over the tab row.
**Result: every sampled pixel matched its token exactly in both themes; no
`--osf-bg-raised` (`#151b23`) was painted, and no stray painter overlaps the
row.** VERDICT: the code is proven correct on a clean profile — the operator's
grey is STALE CACHED CSS. The versioned asset URLs above are the structural
elimination of that failure mode. The diagnostic is committed as a repeatable
(browser-download, non-CI) script: `tests/browser/header-surface-check.mjs`
(run `npx playwright install firefox --with-deps`, `composer serve`, then
`node tests/browser/header-surface-check.mjs`; exits non-zero on any deviation).

## Submission pipeline order (enforced + tested)
method/body size → field hygiene → form lookup by URL key → origin allowlist
→ per-IP then per-form rate limits → honeypot → token → Turnstile (optional)
→ email (syntax + MX/A) → store → delivery (terminal, always succeeds).
Locked by SubmitPipelineOrderTest.

## Other subsystems — condensed; see HISTORY
- Admin deletion: hard-delete + reversible deactivate; three guards (no
  self-delete, last-active protected, web re-verifies password).
- No-JS policy: embed osf.js degrades to native POST; migration 009
  `forms.allow_nojs` governs the honest `javascript_required` on the HTML path.
- Dev tooling: `composer serve` → `public/dev-router.php`; `bin/osf migrate`
  (idempotent) + a red dashboard banner when the schema is behind.
- Packaging: `bin/build-release.php`/`verify-release.php` (shared exclusion
  list, kept in sync by ReleaseManifestTest) → `dist/opensendform-v{VERSION}.zip`;
  `src/Version.php` (0.1.0) is the sole version source. `tests/`, dev configs,
  state files, AND the new dev-only Node manifest (package.json,
  package-lock.json, node_modules) are pruned from the zip.
- Mail/installer/auth/Turnstile/relay: SMTP write-back wizard + deliverability
  checker; installed iff both var/config.php + var/install.lock; argon2id,
  TOTP/recovery, CSRF, hardened sessions; Turnstile optional per form
  (both-or-neither, fail-open); MessageBuilder + DeliveryService (backoff→dead).
  Production Composer deps: phpmailer ^6, slim ^4, slim/psr7, php-di/php-di.

## Dev-only tooling added this sprint
- `package.json` + `package-lock.json` (devDependencies: playwright only);
  `node_modules` gitignored, all three pruned from the release. Node exists
  ONLY in the dev container; production still has no Node/Docker/build step.
- `tests/browser/header-surface-check.mjs` — real-Firefox pixel regression,
  deliberately NOT wired into `composer test`/CI (needs a browser download);
  its `*.png` screenshots are gitignored.

## Known gaps / not built (by design)
- Synthetic monitoring + alerting — the remaining planned increment.
- No AUTOMATIC migration trigger on upgrade (manual `bin/osf migrate` + banner).
- Password reset by email, roles/permissions, audit log; file uploads /
  redirect success URLs (out of embed scope).
- MySQL live-test, NativeSession `$_SESSION`, real SMTP/DNS and osf.js DOM
  behaviour stay un-unit-tested (network/globals/no DOM harness).

## Open items
None blocking. QUESTIONS.md carries only prior, resolved/non-blocking notes;
no new question arose this sprint.

## Planned increment sequence
0–8 (skeleton → schema → pipeline → SMTP → Turnstile → admin auth → design
system → installer → embed → packaging) ALL DONE. 9. Synthetic monitoring +
alerting.
