# OpenSendForm

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

## License
MIT — see LICENSE.