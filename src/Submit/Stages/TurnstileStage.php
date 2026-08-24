<?php

declare(strict_types=1);

namespace OpenSendForm\Submit\Stages;

use OpenSendForm\Submit\Stage;
use OpenSendForm\Submit\SubmitContext;
use OpenSendForm\Submit\SubmitOutcome;
use OpenSendForm\Turnstile\TurnstileResult;
use OpenSendForm\Turnstile\TurnstileVerifierInterface;

/**
 * Stage (g'): optional Cloudflare Turnstile check, between the submit-token
 * stage and email validation.
 *
 * Turnstile is enabled per form: only when the looked-up form carries BOTH a
 * sitekey and a secret. When it does not, this stage returns instantly
 * (no verify call is made). When it does:
 *  - a missing client token (reserved field _osf_cf) is an honest
 *    400 turnstile_required;
 *  - a token Cloudflare positively rejects is an honest 400 turnstile_failed
 *    (the embed JS re-renders the widget);
 *  - a VALID token, or an UNAVAILABLE verify call (unreachable/timeout/
 *    malformed), lets the submission proceed — the fail-open policy, since
 *    the other defences remain in force.
 */
final class TurnstileStage implements Stage
{
    private TurnstileVerifierInterface $verifier;

    public function __construct(TurnstileVerifierInterface $verifier)
    {
        $this->verifier = $verifier;
    }

    public function process(SubmitContext $context): ?SubmitOutcome
    {
        $form = $context->form ?? [];
        $sitekey = (string) ($form['turnstile_sitekey'] ?? '');
        $secret = (string) ($form['turnstile_secret'] ?? '');

        // Disabled unless the form has BOTH a sitekey and a secret. Skip
        // instantly — no network call.
        if ($sitekey === '' || $secret === '') {
            return null;
        }

        $token = $context->turnstileToken;
        if ($token === null || $token === '') {
            return SubmitOutcome::error(400, 'turnstile_required', 'The Turnstile challenge response is missing.');
        }

        $result = $this->verifier->verify($secret, $token, $context->remoteIp);

        if ($result === TurnstileResult::INVALID) {
            return SubmitOutcome::error(400, 'turnstile_failed', 'The Turnstile challenge could not be verified. Please try again.');
        }

        // VALID or UNAVAILABLE: proceed (fail open on UNAVAILABLE).
        return null;
    }
}
