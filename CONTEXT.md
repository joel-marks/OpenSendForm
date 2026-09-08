# OpenSendForm — current state

Last updated: 2026-09-08 (fix/forms-table-scroll-and-stat-links, Claude Code)

## Status
The service is end-to-end: a versioned v1 API drives an ordered
validation/abuse pipeline; passing submissions are stored and relayed by
authenticated SMTP (in-request send + operator retry sweep). Full admin panel
(auth, 2FA, forms/submissions CRUD, mail-setup wizard, browser installer), a
client-site embed artefact with a no-JS fallback, dev tooling, an explicit
migration command, guarded admin deletion and release packaging (v0.1.0 zip +
`.htaccess` set + `bin/osf version`) are all built and merged to main. The
design system is a bespoke `--osf-*` token contract with a two-row
GitHub-aligned header on ONE shared surface, Dark/Light/Auto theme, vendored
Lucide icons and responsive card-collapse tables. Suite green (483 tests).
CI runs tests + a package build/verify on every PR/push.

## Product definition
Free, open-source, self-hostable form-to-email service for shared cPanel/PHP
hosting. One installation serves many websites via an embedded snippet + JS
posting to a central endpoint. Validated, abuse-filtered, relayed by
authenticated SMTP to the site owner.

## Decisions locked
- Name: OpenSendForm. Stack: PHP 8.1+ / Slim 4 / Composer / PHPMailer /
  SQLite default (MySQL optional via PDO). Server-rendered admin UI on a
  bespoke token-driven stylesheet; no JS framework, no build step; JS is
  enhancement-only. No Node/Docker in production.
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

## Submission pipeline order (enforced + tested)
method/body size → field hygiene → form lookup by URL key → origin allowlist
→ per-IP then per-form rate limits → honeypot → token → Turnstile (optional)
→ email (syntax + bounded MX/A) → store → delivery (terminal, always succeeds).
Locked by SubmitPipelineOrderTest. The stage ORDER is locked.

## Mail delivery + retry (updated this sprint)
- In-request: DeliveryStage makes at most one send attempt, then always returns
  success — a delivery failure never changes the submitter's `{"ok":true}`.
  Delivery is SKIPPED (row left 'received') when no mailer is wired, or
  `MAIL_ENABLED` is falsy, or `SMTP_HOST` is empty.
- Retry sweep (`DeliveryService::retryDue`, shared by `bin/osf mail:retry`,
  the dashboard/submissions "Retry all due" and per-row Retry): re-attempts
  BOTH 'failed' rows whose backoff (`next_attempt_at`) has elapsed AND
  never-attempted 'received' rows (`attempts = 0`), the latter treated as
  immediately due with NO backoff. With mail disabled, 'received' rows are
  skipped untouched (no attempt burned, no backoff started); enabling mail
  later finally delivers them. Failure escalates backoff → 'dead' at
  MAIL_MAX_ATTEMPTS. A real failure always sets `last_attempt_at` +
  `next_attempt_at` via `markFailed`; rows missing those were seeded, not real.
- Email-domain DNS check is BOUNDED (this sprint): `BoundedDnsChecker` +
  `UdpDnsTransport` speak DNS over a bounded UDP socket, whole MX-then-A lookup
  ≤ 3 s total. FAIL-OPEN — only a definitive negative (NOERROR w/ no MX+A, or
  NXDOMAIN) rejects; timeout/SERVFAIL/malformed/no-nameserver accept and let
  SMTP arbitrate. Replaced the unbounded `SystemDnsChecker` (removed).

## Design system (condensed — see HISTORY for the full evolution)
- Single source of colour: `public/assets/tokens.css` — the `--osf-*` contract
  on `:root` (dark default) and `[data-theme="light"]`, LIVE-VERIFIED against
  github.com. `DesignSystemTest` forbids hardcoded colour outside tokens.css
  (+ vendor/qrcode.js; embed/osf.js out of scope). Token VALUES are frozen.
- Spacing `--osf-space-1..6`; `.osf-field` is the single label+control+hint
  wrapper (DOM regression check asserts no visible control lacks one).
  `.container` is layout-only by rule.
- Header: two rows (`.osf-header` + `.osf-tabnav`) on ONE shared
  `--osf-bg-inset` surface in both themes, single hairline `--osf-border`
  under row 2 only (architect one-surface ruling, real-Firefox pixel-verified;
  repeatable non-CI script `tests/browser/header-surface-check.mjs`). Tabs are
  GitHub-UnderlineNav style, Lucide icons, `--osf-tab-active` underline.
- Versioned asset URLs: every admin/installer `/assets/` `<link>`/`<script>`
  carries `?v=<Version::STRING>` via `OpenSendForm\Admin\asset()` — structural
  cache-bust. Embed assets (public/embed/*) also carry `?v=` in the snippet.
- Dashboard stat cards are whole-card links (`<a class="osf-stat ...">`) to
  their obvious destination (Active forms -> /admin/forms; Submissions today
  -> /admin/submissions; Failed/Dead -> /admin/submissions?status=failed|dead,
  reusing SubmissionsController's existing `status=` param). Tone
  (`statCardToneClass()` in PHP, never JS) maps value→info/success/danger;
  background stays on the `-subtle` token family, the thin 3px left edge uses
  the FULL-STRENGTH token (`--osf-info`/`--osf-success`/`--osf-danger`) for
  brightness, numerals stay uncoloured. Hover: `filter: brightness(1.08)`;
  keyboard focus: `--osf-focus-ring` via `:focus-visible`. Watch for the
  `a:hover { text-decoration: underline }` base-style specificity trap on any
  future `.osf-*` interactive card — override it explicitly inside the
  component's own `:hover` rule, not just the base selector.
- Tables sharing the `.osf-table` class use the browser's default AUTO
  layout, which sizes a column to its widest row and can push the table past
  its container if a badge/button label varies by row state (hit this on the
  forms table: "disabled" vs "active"). `forms_list.php`'s table also carries
  a `.osf-table--forms` modifier (`table-layout: fixed` + explicit
  percentage column widths + `overflow-wrap: anywhere`) so no row's content
  can reopen a horizontal-scroll bug; this modifier is scoped to that one
  table, not the shared `.osf-table` base (submissions/admins/installer/
  dashboard tables are unaffected and untested against the same class of bug).
- Account menu: native `<details>/<summary>` (no JS, CSP-safe). Theme: default
  dark, toggle cycles Dark/Light/Auto, `theme-init.js` sets pre-paint. Icons
  from a vendored Lucide subset (`src/Admin/icons.php`). Responsive tables
  collapse to cards under 640px; last-error cells are no-JS `<details>`.

## Embed artefact (public/embed/osf.js) + manual harness
- Progressive enhancement is absolute: absent/failed JS still POSTs natively.
  Field errors attach to only their mapped field via an explicit
  `FIELD_FOR_CODE` map (invalid_email + email_domain_invalid → email input;
  every other code → form-level strip). Under the 16 KB EmbedAssetTest ceiling.
- `tests/embed-manual.html` (dev-only, `/embed/manual.html`) ships a STATIC
  fallback form in raw HTML (admin-snippet shape, honeypot) that ALWAYS
  renders, so the no-JS path is testable. JS completes its action + data-osf-*
  from `?key=`; with JS off a `<noscript>` block explains how to finish the
  action by hand (pure HTML cannot inject a query param; harness must not
  server-render).

## Other subsystems — condensed; see HISTORY
- Admin deletion: hard-delete + reversible deactivate; three guards.
- No-JS policy: migration 009 `forms.allow_nojs` governs the honest
  `javascript_required` on the HTML path.
- Dev tooling: `composer serve` → `public/dev-router.php`; `bin/osf migrate`
  (idempotent) + a red dashboard banner when the schema is behind.
- Packaging: `bin/build-release.php`/`verify-release.php` (shared exclusion
  list, kept in sync by ReleaseManifestTest) → `dist/opensendform-v{VERSION}.zip`;
  `src/Version.php` (0.1.0) is the sole version source; tests/, dev configs,
  state files and the dev-only Node manifest are pruned from the zip.
- Mail/installer/auth/Turnstile/relay: SMTP write-back wizard + deliverability
  checker; installed iff both var/config.php + var/install.lock; argon2id,
  TOTP/recovery, CSRF, hardened sessions; Turnstile optional per form. Prod
  Composer deps: phpmailer ^6, slim ^4, slim/psr7, php-di/php-di.

## Known gaps / not built (by design)
- Synthetic monitoring + alerting — the remaining planned increment.
- No AUTOMATIC migration trigger on upgrade (manual `bin/osf migrate` + banner).
- Password reset by email, roles/permissions, audit log; file uploads /
  redirect success URLs (out of embed scope).
- MySQL live-test, NativeSession `$_SESSION`, real SMTP stay un-unit-tested.
  DNS is now unit-tested via the `DnsTransport` seam; only the real
  `UdpDnsTransport` socket path (network) is not. osf.js DOM behaviour is
  verified by ad-hoc headless-browser checks, not CI (no DOM harness by policy).

## Open items
None blocking. QUESTIONS.md carries prior resolved/non-blocking notes plus two
new non-blocking notes this sprint (Task 4 bug non-reproduction; bounded-DNS
mechanism choice).

## Planned increment sequence
0–8 (skeleton → schema → pipeline → SMTP → Turnstile → admin auth → design
system → installer → embed → packaging) ALL DONE. 9. Synthetic monitoring +
alerting.
