# OpenSendForm — current state

Last updated: 2026-08-20 (seed, architect-authored; Claude Code owns
this file from sprint 1 onward)

## Status
Pre-code bootstrap. Repo contains devcontainer, standing orders, and
state files only. No application code yet.

## Product definition
Free, open-source, self-hostable form-to-email service for shared
cPanel/PHP hosting. One installation serves many websites via embedded
snippet + JS posting to a central endpoint. Validated, abuse-filtered,
relayed by authenticated SMTP to the site owner.

## Decisions locked
- Name: OpenSendForm. Domains opensendform.com/.org are project/docs
  site only — no installation depends on them at runtime.
- Stack: PHP 8.1+ / Slim 4 / Composer / PHPMailer / SQLite default
  (MySQL optional via PDO). Server-rendered admin UI, no JS frameworks.
- Distribution: release zip with vendored dependencies + browser-based
  installer (WordPress pattern: upload, extract, visit /install,
  installer self-locks). Softaculous is a later ambition.
- Admin panel: required. Argon2id passwords, TOTP 2FA, CSRF tokens,
  rate-limited login, hardened sessions.
- Public endpoint defences: per-client API keys, origin allowlists,
  Cloudflare Turnstile (optional per form), honeypot, minimum
  time-to-submit, per-IP and per-key rate limits, payload caps, strict
  validation, email header injection prevention.
- Mail policy: always From: the service domain, Reply-To: the
  submitter. Never From: the submitter (DMARC).
- Submitter email verification: REJECTED (deliverability risk + mail-
  bomb attack surface). Replaced by: MX/DNS check on submitter domain,
  client-side typo suggestion, optional disposable-domain blocklist.
- No "send me a copy" feature ever (backscatter vector).
- Storage: metadata always stored; message-content storage is a
  per-form toggle with configurable retention purge.
- Dev email: Mailpit only.

## Open items
- Increment 0 (app skeleton) not yet run.

## Planned increment sequence (subject to revision)
0. Composer/Slim skeleton, PHPUnit harness, SQLite storage layer,
   dev-server script.
1. Schema + form/API-key model.
2. Submission endpoint + validation/abuse middleware stack.
3. SMTP relay (PHPMailer) with retry.
4. Turnstile integration.
5. Admin panel + auth (argon2id, TOTP, CSRF).
6. Browser installer + environment autodetection.
7. Embed snippet + JS.
8. Synthetic monitoring + alerting.