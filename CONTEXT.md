# OpenSendForm — current state

Last updated: 2026-08-25 (feature/increment-6b-mail-wizard, Claude Code)

## Status
The public submission endpoint is live end-to-end and RELAYS BY EMAIL: a
versioned v1 API drives an ordered validation/abuse pipeline; passing
submissions are stored and one in-request SMTP send is attempted, failures
retried by an operator cron. 4 added optional per-form Turnstile. 5a/5b/5c built
the ADMIN stack (argon2id auth, optional TOTP 2FA + recovery codes, Pico.css
design system, forms/submissions CRUD, single-tenant account/admins mgmt). 6a
added the BROWSER INSTALLER ENGINE (merged config factory, written config +
lock, requirements check, CSRF wizard that self-locks). 6b adds the MAIL-SETUP
WIZARD in the admin panel (SMTP settings write-back, test send, SPF/DKIM/DMARC
deliverability checker) plus installer polish. Suite green (381 tests). CI runs
it on every PR/push. NOTE: 6a is not yet merged to main; 6b is branched off 6a
(see QUESTIONS.md).

## Product definition
Free, open-source, self-hostable form-to-email service for shared cPanel/PHP
hosting. One installation serves many websites via embedded snippet + JS posting
to a central endpoint. Validated, abuse-filtered, relayed by authenticated SMTP
to the site owner.

## Decisions locked
- Name: OpenSendForm. Stack: PHP 8.1+ / Slim 4 / Composer / PHPMailer /
  SQLite default (MySQL optional via PDO). Server-rendered admin UI on
  Pico.css; no JS framework, no build step; JS is enhancement-only.
- All SQL portable across sqlite + mysql; timestamps stored as UTC `Y-m-d
  H:i:s` TEXT. Form keys are PUBLIC identifiers, stored plain.
- Response contract (FROZEN): JSON only. Success `{"ok":true}`; failure
  `{"ok":false,"error":{"code","message"}}`. HTTP: 200/400/403/405/413/429.
- Bot-facing checks fail SILENTLY; an authentic but EXPIRED token returns an
  honest `400 token_expired`.
- Content always stored as the in-flight delivery payload; `store_content`
  means "retain content after successful delivery".
- Config precedence: defaults < var/config.php < environment (env always wins,
  so the dev container is unchanged). Env-shadowed file values are surfaced.

## Mail relay (Increment 3) — condensed; see HISTORY
- `Mail\MessageBuilder`: From ALWAYS the service address; Reply-To the
  submitter's valid `email` only; no submitter data in any header.
- `Mail\DeliveryService`: attemptDelivery (sent/clearContent unless store_content;
  failed+backoff; dead at MAIL_MAX_ATTEMPTS) + retryDue. Fake-clock testable.
  MAIL_ENABLED is the primary switch (AND smtpHost non-empty). `bin/osf`
  mail:retry / mail:test.

## Turnstile (Increment 4) — condensed; see HISTORY
- Optional PER FORM, both-or-neither. Verifier interface + Curl impl + fake;
  fail-open. Secret NEVER exposed. `bin/osf form:turnstile`.

## Admin stack (5a/5b/5c) — condensed; see HISTORY
- Migrations 007/008 `admins` (+is_active). Auth primitives (PasswordHasher
  argon2id, Base32, Totp RFC 6238, RecoveryCodes, AdminRepository). Session seam
  (NativeSession/FakeSession), Csrf, AuthService (rate limits, idle/absolute
  timeouts, TOTP gate). Vendored MIT assets (Pico.css 2.0.6, qrcode 1.4.4).
  AdminView chrome, Flash, TemplateRenderer + `h()`. Strict CSP; every screen
  works JS-off, no inline scripts. Screens: dashboard, forms CRUD (write-only
  secret), submissions (metadata only), account, admins (last-active guard), 2FA
  lifecycle. Single-tenant; no roles; retirement = deactivation.

## Browser installer engine (Increment 6a) — condensed; see HISTORY
- Installed iff BOTH var/config.php and var/install.lock exist
  (`Install\Paths::isInstalled()`). `InstallStateMiddleware` gates routing
  (opt-in via install Paths to AppFactory). Config refactor: `fromFile`,
  merged `load(file,env)`, `generateSecret()` (64 hex), DB_USER/DB_PASS.
  Requirements (injectable probe), InstallerService (DbConnector seam, atomic
  temp+rename+0600 commit, config-then-lock with rollback), CSRF wizard
  (welcome→database→admin→finish→done), `bin/osf install:status`, OSF_BASE_DIR.
  Written config sets MAIL_ENABLED=0.

## Mail-setup wizard + installer polish (Increment 6b) — this sprint
- `/admin/mail` (nav "Email", `Admin\MailController` + `templates/admin/mail.php`):
  SMTP host/port/encryption(none/starttls/smtps)/user/password + From
  address/name + MAIL_ENABLED toggle. Validation; save writes var/config.php via
  `Install\ConfigWriter` (atomic temp+rename+0600, merges over stored values so
  untouched keys survive). Password is WRITE-ONLY (blank keeps the stored file
  value); the stored secret is never rendered. When an env var shadows a stored
  file value, a notice names the setting (file-value vs effective compare).
- Test send: POST /admin/mail/test uses the container `MailerInterface` (real
  PhpMailerMailer built from current Config, or an injected fake) on the SAVED
  settings; defaults recipient to the logged-in admin; flashes success or the
  sanitised+truncated error. A success while sending is off unlocks a one-click
  "Enable sending now" (POST /admin/mail/enable → MAIL_ENABLED=1).
- Deliverability: `Mail\DeliverabilityChecker` over an injectable
  `Mail\DnsResolver` (SystemDnsResolver = dns_get_record TXT, offline-safe;
  FakeDnsResolver in tests). SPF (TXT `v=spf1`), DKIM (`{selector}._domainkey`,
  selector re-checkable, key comes from host), DMARC (`_dmarc` `v=DMARC1`).
  Each: green/amber, the exact record to add (copy button, reusing `[data-copy]`)
  and where. Malformed record → treated as absent.
- Handoff: installer done screen links `/admin/mail`; dashboard shows a second
  dismissible nudge "Email sending is not set up yet" (mirrors the 2FA nudge)
  when MAIL_ENABLED is off (POST /admin/nudge/mail/dismiss).
- Installer polish: /install/database MySQL section is `data-mysql-details`,
  hidden by `public/assets/install.js` while SQLite is chosen (radios are
  `data-db-driver`); visible with JS off (unchanged layout).
- CLI: `bin/osf mail:status` prints config state (no secrets) + live SPF/DKIM/
  DMARC lookups for the From domain (skips gracefully offline).

## Submission pipeline order (enforced + tested)
method/body size → field hygiene → form lookup by URL key → origin allowlist
→ per-IP then per-form rate limits → honeypot → token → Turnstile (optional)
→ email (syntax + MX/A) → store → delivery (terminal, always succeeds).
Locked by SubmitPipelineOrderTest.

## What exists now
0–6a as before, plus 6b: `src/Mail/{DnsResolver,SystemDnsResolver,
DeliverabilityChecker}.php`, `src/Install/ConfigWriter.php`,
`src/Admin/MailController.php`, `templates/admin/mail.php`,
`public/assets/install.js`; AppFactory wiring (MailerInterface, ConfigWriter,
DnsResolver, DeliverabilityChecker; 9th `?DnsResolver` param); AdminRoutes mail
routes + mail-nudge dismiss; dashboard mail banner; `bin/osf mail:status`;
tests `tests/Mail/DeliverabilityCheckerTest`, `tests/Install/{ConfigWriterTest,
InstallerDatabaseToggleTest}`, `tests/Admin/MailWizardHttpTest`,
`tests/Cli/CliMailStatusTest`, support `FakeDnsResolver`. Only hard Composer dep
remains phpmailer/phpmailer ^6.

## Known gaps / not built (by design)
- Embed snippet/JS (7), synthetic monitoring (8), zip packaging/release layout,
  upgrade/migration-on-update — later increments.
- Password RESET by email, roles/permissions, account deletion, audit log.
- Per-form mail overrides, provider-specific SMTP presets (nice-to-have later).
- Real MySQL live-test, NativeSession `$_SESSION`, real SMTP send and live DNS
  stay un-unit-tested (network/globals); covered via fakes. No DOM harness.

## Open items
- QUESTIONS.md: Increment 6b item 1 flags branch/merge order (6a not on main;
  6b built on 6a). No blocking code questions.

## Planned increment sequence (subject to revision)
0. Skeleton. 1. Schema. 2. Pipeline. 3. SMTP relay. 4. Turnstile. 5a. Admin
auth. 5b. Design system + CRUD. 5c. Account mgmt + 2FA lifecycle. 6a. Installer
engine. 6b. Email-setup (SMTP/DNS) wizard + installer polish. — ALL DONE
7. Embed snippet + JS. 8. Synthetic monitoring + alerting.
