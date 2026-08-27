# Open questions for the architect

## Increment 7

1. **No-JS submissions vs the token stage — SECURITY/SCOPE. RESOLVED
   (2026-08-26, fix/nojs-policy).** Architect ruling: refine option (b) below
   into a per-form toggle. Migration 009 adds
   `forms.allow_nojs INTEGER NOT NULL DEFAULT 0`.
   - `allow_nojs = 0` (default): an HTML-negotiated (no-JS) POST with a
     missing, forged or too-young token gets an HONEST error page — *"This
     form requires JavaScript to submit. Your message was not sent."*
     (`400 javascript_required`) — never the fake-success page, and nothing
     is stored. This is a deliberate, documented exception to "bot checks
     fail silently": a full-page navigation that silently claimed success
     would actively mislead a genuine no-JS submitter, which is worse than
     an honest failure telling them what to do (enable JS, or ask the site
     owner to turn the flag on).
   - `allow_nojs = 1`: a *missing* token on the HTML-negotiated path skips
     the token check entirely (the min-time bot check is knowingly waived
     for this subset) so a genuine no-JS submission is stored and delivered.
     A *present but forged/too-young* token on this same path is still
     treated as a bot signature and falls through to the ordinary silent
     discard — the waiver is narrowly for "no token at all", not for a bad
     one. All other stages (origin, rate limits, honeypot, email MX,
     Turnstile) apply unchanged.
   - Honeypot hardening: the admin embed-code panel's snippet now ships the
     `_osf_hp` field as a static hidden `<input>` (inline `display:none` +
     `aria-hidden` + `tabindex="-1"`), so it protects no-JS posts too;
     `osf.js` reuses it instead of injecting a duplicate when already present.
     A filled honeypot on the HTML path still gets the generic success page
     (never the honest error) and is discarded either way — humans never
     fill it, so there is no honest signal to give back.
   - JSON/fetch path is completely unchanged regardless of `allow_nojs`
     (locked by regression tests): the embed always sends a token, so this
     toggle only ever affects a plain `<form>` POST with the script absent.
   - Admin form-edit gained an "Allow submissions without JavaScript"
     checkbox with an inline trade-off sentence (reduced bot protection;
     Turnstile-enabled forms cannot be submitted without JavaScript either
     way, since Turnstile itself needs the script); `bin/osf form:list` shows
     an `[allow_nojs]` tag. See `src/Submit/Stages/TokenStage.php`,
     `tests/Http/SubmitHtmlResponseTest.php`, HISTORY.md.

   Original question, for context: "Progressive enhancement is absolute": a
   plain form must POST to the endpoint with JS off and work. But a no-JS
   POST carries no `_osf_token` (the token is fetched and injected by JS),
   and the LOCKED `TokenStage` treated a *missing* token as a bot signature —
   it returned a silent fake success and stored/relayed nothing. Net effect:
   a genuine no-JS submitter saw the "Message sent" HTML page but no email
   was delivered. Options considered were (a) accept no-JS as
   best-effort/degraded (simplest, no code change); (b) distinguish a
   completely absent token from a forged/too-young one — missing → proceed,
   forged/too-young → keep the silent discard (chosen, gated per-form); (c) a
   server-issued token embeddable in the static snippet — not currently
   possible (the snippet is static HTML the site owner pastes).

2. **`osf.js` is 15.4 KB unminified, over the 12 KB brief target. RATIFIED
   (2026-08-26, architect ruling, fix/nojs-policy).** Keep the readable
   ~15 KB file with the 16 KB `EmbedAssetTest` regression guard — option (a)
   below, as proposed. No further action; the guard stays as the ongoing
   regression check.

   Original question, for context: the locked rich-UX feature set (submitting
   spinner, success overlay dialog with focus trap + Esc/OK, submitted panel
   with "Send another", inline field errors + form-level strip, invisible
   `token_expired` retry, Turnstile load/render/reset, aria-live
   announcements, themeable injected CSS, `prefers-reduced-motion`,
   `data-osf-ui="none"`, events) does not fit in 12 KB as *readable,
   unminified* source — even stripped of all indentation, blank lines and
   comments the code floor is ~12.5 KB, so 12 KB is unreachable without
   cutting a feature or shipping a build artefact. "No build step" and
   "unminified" rule out minification. Options were: (a) accept the readable
   ~15 KB (chosen/ratified); (b) drop/trim a feature to approach 12 KB;
   (c) permit a committed minified build despite the no-build-step rule.


## Increment 6b

1. **Branch base: 6b was built on `feature/increment-6a-installer`, not `main`.
   FLAG FOR MERGE ORDER (not blocking). RESOLVED (2026-08-26): 6a then 6b were
   merged to `main` via PRs #15 and #16; Increment 7 branches cleanly off main.**
   The 6b task prompt said to branch from `main`, but Increment 6a (the browser
   installer engine) has not been merged to `main` yet — its `src/Install/*`,
   the atomic config writer, `Config::load/fromFile`, `DB_USER/DB_PASS` and the
   installer done screen live only on the 6a branch. Task 4 (done-screen +
   dashboard handoff) and the "config write-back via the existing atomic writer"
   requirement both depend on 6a. Branching 6b from `main` would have dropped all
   of that. Decision: branch 6b off `feature/increment-6a-installer`. Merge 6a
   first, then 6b (or merge 6b, which contains 6a's commits, as one). No code
   question here — just merge-order awareness.


## Increment 2

1. **/v1/submit CORS preflight origin decision. RESOLVED (2026-08-24).**
   The any-active-form preflight match was rejected: it let a caller
   enumerate an installation's registered origins by probing preflights
   with different Origin headers. Fix: the form key moved into the URL
   (`POST /v1/form/{form_key}/submit`), so the preflight resolves the
   specific form and echoes the origin only when that form's own allowlist
   matches — exact, per-form, no enumeration surface. See
   fix/submit-route-form-key and the HISTORY.md entry for 2026-08-24.

## Increment 3

1. **Delivery content vs. the `store_content` toggle. RESOLVED (2026-08-24).**
   Decision: option (a) — content is always stored at submission time as the
   in-flight delivery payload; `store_content` now governs retention after a
   successful send (cleared on `sent` when off, kept when on; `failed`/`dead`
   always keep it for retry/recovery until the retention purge). See
   fix/mail-content-lifecycle and the HISTORY.md entry for 2026-08-24.

   Original question, for context:
   `DeliveryService::attemptDelivery()` loads the submission row and builds the
   email from its stored `content` (as the sprint prompt specifies). But
   `store_content` defaults OFF and, when off, the `content` column is NULL —
   so the relayed email lists no fields, and a retry has nothing to resend.
   For a form-to-email service this is the wrong default for the core use
   case. Options for the architect:
   (a) store submitted content unconditionally for pending delivery and let
       `store_content` govern *retention* (purge/redact after a successful
       send) rather than initial storage — cleanest, but changes the meaning
       of the existing toggle and the `testStoreContentOffKeepsMetadataOnly`
       assertion;
   (b) add a separate, always-populated delivery-payload column consumed and
       cleared by the mailer, leaving `store_content` about admin retention;
   (c) have the in-request first attempt use the live posted fields (always
       available) and accept that retries of `store_content=0` forms carry
       metadata only.
   Implemented as specified for now (reads `content`; empty when off). The
   local test recipe enables `store_content` so the email shows fields.

2. **Storage-only requires an empty `SMTP_HOST`, which `fromEnvironment()`
   cannot express. RESOLVED (2026-08-24).**
   Decision: added a dedicated `MAIL_ENABLED` config flag (env-overridable,
   default '1'). It is now the primary delivery switch — delivery is
   attempted only when `MAIL_ENABLED` is truthy AND `SMTP_HOST` is
   non-empty — with the host check kept only as a secondary guard. See
   fix/mail-content-lifecycle and the HISTORY.md entry for 2026-08-24.

   Original question, for context:
   The empty-value fallback in `Config::fromEnvironment()` keeps the
   `SMTP_HOST` default ('localhost') even when the env sets it to '' (locked
   by `testBlankAndFalseValuesFallBackToDefaults`). Storage-only operation
   therefore can't be selected by blanking `SMTP_HOST` through the
   environment. Handled by: the front controller passes a real mailer only
   when `SMTP_HOST !== ''`, and `Config::fromValues()` (explicit construction,
   empties honoured) exists for tests/programmatic callers. If operators are
   expected to disable mail via env, a dedicated `MAIL_ENABLED` flag (empty by
   default) would be clearer than overloading `SMTP_HOST` — flagging for the
   installer increment.

## Increment 8

1. **The zip PHP extension is not available in this dev container — DEVIATION,
   resolved without asking.** The task prompt stated the build tooling would use
   "PHP + zip extension". In this container `ZipArchive` is not compiled into
   PHP (`php -m` shows no `zip`; there is no `zip.so`), though the `zip` and
   `unzip` CLI binaries are present. Rather than add/build an extension (which
   is not a Composer dependency and would be a container change), the
   build/verify scripts use `ZipArchive` when it is loaded and fall back to the
   `zip`/`unzip` CLIs otherwise (`bin/release_lib.php`: `osf_zip_dir`,
   `osf_unzip`). The CI `package` job installs PHP with the `zip` extension, so
   CI exercises the `ZipArchive` path; local dev-container runs exercise the CLI
   fallback. Both produce/verify the same artefact. No architect input needed —
   flagged only so the extension gap in the base image is on record (worth
   adding `zip` to the devcontainer Dockerfile in a future housekeeping pass).

## Increment 5d

1. **Devcontainer zip extension — now added (follows up Inc 8 #1).** The
   housekeeping pass suggested in Increment 8 #1 is done: `.devcontainer/
   Dockerfile` now installs `libzip-dev` + `docker-php-ext-install zip`, so the
   `ZipArchive` path works locally after the next container REBUILD. The
   CLI fallback in `bin/release_lib.php` is retained as belt-and-braces. No
   architect input needed — noted for the record.

2. **Shared token contract with the docs site — ASSUMPTION, non-blocking.** The
   task frames `tokens.css` as "shared with the docs site" and the README now
   says the docs site loads the same `tokens.css`. The docs site
   (opensendform.com) is out of this repo's scope, so this sprint only produces
   the app-side contract; keeping the two in sync is a cross-repo convention,
   not something enforced here. If the docs site should instead vendor a copy or
   consume a published artefact, say so and we'll adjust the README wording.

3. **No-hardcoded-colours grep scope — DESIGN NOTE, non-blocking.** The
   enforcement test (`DesignSystemTest::testNoHardcodedColoursOutsideTokens`)
   forbids hex and `rgb()/hsl()` everywhere it scans (templates/ +
   `public/assets/*.css|js`), and additionally forbids CSS *named* colours in
   `.css/.js` files only — named-colour matching is skipped for templates to
   avoid false positives on English prose. Exemptions: `tokens.css` (the
   contract) and `vendor/qrcode.js`; `public/embed/osf.js` is out of scope by
   the sprint's scope fence. Flagged so the chosen enforcement boundary is on
   record.
