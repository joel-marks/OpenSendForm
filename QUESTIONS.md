# Open questions for the architect

## Increment 2

1. **/v1/submit CORS preflight origin decision.** The spec says the
   preflight should echo `Access-Control-Allow-Origin` "only when allowed",
   but at preflight time the browser has not sent a body, so the target
   form (and thus its allowlist) is unknown — only the `Origin` header is
   available. Decision taken: the submit preflight echoes the origin if it
   is allowlisted by **any active form**, otherwise withholds it. The real
   per-form authorisation still happens on the POST itself (the origin
   stage), so this only affects whether the browser is permitted to send
   the request, not whether it is accepted. Please confirm this is the
   intended behaviour, or specify an alternative (e.g. always echo, or
   require the key as a query param on the preflight).
