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
   default, it's cleared once the email is sent; on, it stays. Until the
   admin UI ships you can enable retention directly:
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

Everything else is treated as user form data. If a field named `email` is
present its syntax and mail-capable DNS record are checked.

The request passes an ordered gauntlet: method/size → field hygiene →
form lookup → origin allowlist → per-IP then per-form rate limits →
honeypot → token → email validation → store. Abuse checks that only a
bot would trip (filled honeypot; missing, forged or too-fast token) return
a normal `{"ok":true}` and silently discard the submission — bots get no
signal. A legitimate but **expired** token is the one honest timing
failure: it returns `400 token_expired` so the client can refresh and
retry. Other failures return specific codes: `invalid_fields`,
`unknown_form`, `origin_not_allowed`, `rate_limited`, `payload_too_large`,
`method_not_allowed`, `invalid_email`, `email_domain_invalid`.

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