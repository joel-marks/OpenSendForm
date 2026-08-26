<?php

declare(strict_types=1);

namespace OpenSendForm\Submit\Stages;

use OpenSendForm\Submit\Stage;
use OpenSendForm\Submit\SubmitContext;
use OpenSendForm\Submit\SubmitOutcome;

/**
 * Stage (f): honeypot.
 *
 * The _osf_hp field is invisible to humans; only a bot fills it. A
 * non-empty value returns a normal success while the submission is
 * silently discarded — the bot gets no signal that it was caught. This is
 * unconditional: on the HTML-negotiated (no-JS) path this same success
 * outcome renders the generic "Message sent" page (SubmitHtmlPage), not the
 * honest javascript_required error — a filled honeypot is never a genuine
 * no-JS submitter (humans never fill it, JS or not), so there is no honest
 * signal to give back, unlike a merely missing/invalid token.
 */
final class HoneypotStage implements Stage
{
    public function process(SubmitContext $context): ?SubmitOutcome
    {
        if ($context->honeypot !== null && $context->honeypot !== '') {
            return SubmitOutcome::success();
        }

        return null;
    }
}
