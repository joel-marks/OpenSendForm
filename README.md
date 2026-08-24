# OpenSendForm

[![CI](https://github.com/joel-marks/OpenSendForm/actions/workflows/ci.yml/badge.svg)](https://github.com/joel-marks/OpenSendForm/actions/workflows/ci.yml)

**Status: pre-alpha. Nothing here is usable yet. Do not install.**

OpenSendForm will be a free, open-source, self-hostable form-to-email
service for ordinary shared hosting. Install it once on any cPanel/PHP
host — upload a zip, run a browser installer — and any number of
websites (static sites, WordPress, anything) can embed a small HTML
snippet that posts form submissions to it. OpenSendForm validates the
submission, filters abuse programmatically, and relays it by
authenticated email to the site owner. No third-party form service, no
subscriptions, no Node or Docker on the server, and nothing for end
clients to maintain.

## Planned stack
PHP 8.1+ · Slim 4 · SQLite (MySQL optional) · PHPMailer · optional
Cloudflare Turnstile. Server-rendered admin panel with 2FA.

## Development
This repo is developed AI-assisted (Claude Code in a devcontainer)
under human architectural direction. See CLAUDE.md, CONTEXT.md and
HISTORY.md for project state and process.

### Getting started
1. Open the repo in VS Code and **Reopen in Container** (Dev Containers
   extension). This provisions PHP 8.1, Composer, and a Mailpit sidecar.
2. Install dependencies:
   ```
   composer install
   ```
3. Run the test suite:
   ```
   composer test
   ```
4. Start the dev server (serves `public/` on port 8080):
   ```
   composer serve
   ```
   Then check `http://localhost:8080/health`.

Dev email is captured by **Mailpit** — open its web UI at
`http://localhost:8025`. No real SMTP server is configured in this
environment.

### End-to-end local test (submission → email)

Drive a real submission all the way to a captured email. The dev container
already points SMTP at Mailpit (`SMTP_HOST=mailpit`, `SMTP_PORT=1025`).

1. Create a form and note its `form_key`:
   ```
   php bin/osf form:create --name="Contact" \
     --recipient="owner@example.com" --origin="http://localhost:8080"
   ```
   Metadata is always stored, and submitted content is always held
   in-flight so the relayed email carries the submitted fields and a failed
   send can be retried. The form's `store_content` toggle only decides
   whether that content is *retained* after a successful delivery — off by
   default, it's cleared once the email is sent; on, it stays. Toggle it per
   form from the admin UI (Forms → edit → *Retain content after delivery*),
   or directly for a quick local test:
   ```
   sqlite3 var/data/opensendform.sqlite "UPDATE forms SET store_content = 1;"
   ```

2. Start the dev server:
   ```
   composer serve
   ```

3. Fetch a submit token (`Origin` must match the form's allowlist):
   ```
   curl "http://localhost:8080/v1/form/<form_key>/token" \
     -H "Origin: http://localhost:8080"
   ```

4. Post a submission with that token. Wait ~3 seconds after fetching it —
   submissions faster than `MIN_SUBMIT_SECONDS` are treated as bots and
   silently dropped:
   ```
   curl "http://localhost:8080/v1/form/<form_key>/submit" \
     -H "Origin: http://localhost:8080" \
     --data-urlencode "_osf_token=<token>" \
     --data-urlencode "name=Ada Lovelace" \
     --data-urlencode "email=ada@example.com" \
     --data-urlencode "message=Hello from a real submission"
   ```
   The response is `{"ok":true}`.

5. Open Mailpit at `http://localhost:8025` — the relayed email is waiting,
   `From: OpenSendForm`, with the submitter's address as `Reply-To`.

`php bin/osf submissions:list` shows the row move to status `sent`. If SMTP
is unreachable it is left `failed` with a scheduled retry; `php bin/osf
mail:retry` (wire it to cron on cPanel) re-sends everything due. A quick
transport check without a submission: `php bin/osf mail:test --to=you@example.com`.

## Cloudflare Turnstile (optional, per form)

[Turnstile](https://developers.cloudflare.com/turnstile/) is Cloudflare's
free, privacy-friendly CAPTCHA alternative. It is **optional and configured
per form** — there is no global switch. A form uses Turnstile only once it
has *both* a sitekey and a secret stored; otherwise the challenge is skipped.

1. In the Cloudflare dashboard (free, no paid plan needed) create a Turnstile
   widget and note its **Site Key** (public) and **Secret Key** (private).
2. Enable it for a form, or clear it again:
   ```
   php bin/osf form:turnstile <ID> --sitekey=<SITE_KEY> --secret=<SECRET_KEY>
   php bin/osf form:turnstile <ID> --disable
   ```
   Both keys are required to enable; `--disable` clears both. The secret is
   stored server-side and is **never** returned by any endpoint — the token
   endpoint advertises only the sitekey (`{"...","turnstile":{"sitekey":...}}`)
   so the embed JS can render the widget.

The client sends the widget's token back in the reserved field **`_osf_cf`**.
When Turnstile is enabled a missing token is rejected with `turnstile_required`
and a token Cloudflare rejects with `turnstile_failed`. The policy is
**fail-open**: if Cloudflare's verify API is unreachable, times out or replies
with malformed data the submission is allowed through (the honeypot, submit
token and rate limits still apply) — only a response that positively says the
token is invalid rejects it.

## Admin panel

The admin panel is served from `/admin` — server-rendered plain PHP on
[Pico.css](https://picocss.com/), no JavaScript framework and no build step.
Every screen works with JavaScript disabled; JS only adds progressive
enhancements (theme toggle, click-to-copy, QR code, segmented 2FA inputs).

### Screens

- **Dashboard** (`/admin`) — at-a-glance counts (active forms, submissions
  today, failed/dead totals) and the ten most recent failed/dead submissions,
  each linking through to the filtered submissions view.
- **Forms** (`/admin/forms`) — list every form (public key with a copy
  button, recipient, active and Turnstile badges) and create/edit them:
  recipient, allowed origins (one per line), the *retain content after
  delivery* toggle, retention days, active state and the optional Turnstile
  key pair. Forms can be enabled/disabled from the list.
- **Submissions** (`/admin/submissions`) — a paginated (50/page), filterable
  table of delivery **metadata only** — id, form, created, status, attempts
  and the last error. Failed/dead rows can be retried individually, and
  *Retry all due now* runs the whole due queue. The fields a visitor typed
  are never displayed (that content is the in-flight delivery payload; see
  the storage note above).

### Theming

The UI ships a light and a dark palette. By default it follows the
browser/OS `prefers-color-scheme`; a toggle in the top nav sets an explicit
choice, persisted in `localStorage` and applied before first paint (no
flash). All colour pairs meet WCAG AA contrast. Pico.css and the QR-code
generator are vendored under `public/assets/vendor/` — nothing is fetched
from a CDN at runtime, and a strict Content-Security-Policy on `/admin`
keeps every source self-hosted.

### Administrators & 2FA

Administrators are **provisioned both from the CLI and, once you have one
account, from the UI** — there is no public sign-up. Create the first admin
from the CLI (you are prompted for the password interactively; it is never
passed on the command line, and input is hidden when a terminal is
available; passwords must be at least 12 characters):

```
php bin/osf admin:create --email=you@example.com --name="Your Name"
```

Then browse to `/admin/login` (e.g. `http://localhost:8080/admin/login` with
the dev server) and sign in.

Two-factor authentication (TOTP) is optional and set up from the dashboard
after signing in (**Set up two-factor authentication**): scan the QR code
(rendered in-browser; the secret never leaves the page) or enter the key
manually into an authenticator app, confirm with the 6-digit code, then save
the one-time **recovery codes** shown once (copy or download them). With 2FA
enabled, sign-in requires a current code (or a recovery code if you lose your
device).

Forms can equally be provisioned from the CLI — see `php bin/osf form:create`
and `php bin/osf form:turnstile` above — so scripted setup and the UI stay
interchangeable.

## Public API (v1)

The public API is JSON-only and CORS-aware. All responses share one shape:

```jsonc
// success
{ "ok": true }
// failure
{ "ok": false, "error": { "code": "snake_case_code", "message": "..." } }
```

Error `code` values are stable and machine-readable; `message` is for
humans and may change. HTTP status is `200` on success, and `400` / `403`
/ `405` / `413` / `429` for the various failures.

### `GET /v1/form/{form_key}/token`
Issues a short-lived submit token for a form. The request's `Origin` must
be in the form's allowlist. Returns `{"ok":true,"token":"<ts>.<hmac>"}`.
Failures: `unknown_form` (unknown/inactive key), `origin_not_allowed`.
The embed JS calls this before rendering a form and refreshes it on
`token_expired`.

### `POST /v1/form/{form_key}/submit`
Accepts `application/x-www-form-urlencoded`, `multipart/form-data` or
`application/json`. The form key travels in the URL, not the body, so
CORS preflights can be resolved exactly per form. Reserved body fields:

- `_osf_token` — the token from the endpoint above
- `_osf_hp` — honeypot; must be left empty
- `_osf_cf` — Turnstile token; only required when the form has Turnstile
  enabled (see above)

Everything else is treated as user form data. If a field named `email` is
present its syntax and mail-capable DNS record are checked.

The request passes an ordered gauntlet: method/size → field hygiene →
form lookup → origin allowlist → per-IP then per-form rate limits →
honeypot → token → Turnstile (if enabled) → email validation → store.
Abuse checks that only a
bot would trip (filled honeypot; missing, forged or too-fast token) return
a normal `{"ok":true}` and silently discard the submission — bots get no
signal. A legitimate but **expired** token is the one honest timing
failure: it returns `400 token_expired` so the client can refresh and
retry. Other failures return specific codes: `invalid_fields`,
`unknown_form`, `origin_not_allowed`, `rate_limited`, `payload_too_large`,
`method_not_allowed`, `invalid_email`, `email_domain_invalid`,
`turnstile_required`, `turnstile_failed`.

A stored submission is relayed by authenticated SMTP to the form's
recipient. One send is attempted in-request; a failure never changes the
`{"ok":true}` response — the submission is safely stored and retried by the
operator's `mail:retry` cron. `From` is always the configured service
address; the submitter's `email` field, when valid, becomes `Reply-To` and
nothing else the submitter typed can reach a mail header. With no SMTP host
configured the app runs storage-only and submissions stay at `received`.
`OPTIONS` on both routes returns a `204` CORS preflight.

## License
MIT — see LICENSE.