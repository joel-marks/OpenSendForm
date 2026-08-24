# Open questions for the architect

## Increment 2

1. **/v1/submit CORS preflight origin decision. RESOLVED (2026-08-24).**
   The any-active-form preflight match was rejected: it let a caller
   enumerate an installation's registered origins by probing preflights
   with different Origin headers. Fix: the form key moved into the URL
   (`POST /v1/form/{form_key}/submit`), so the preflight resolves the
   specific form and echoes the origin only when that form's own allowlist
   matches — exact, per-form, no enumeration surface. See
   fix/submit-route-form-key and the HISTORY.md entry for 2026-08-24.
