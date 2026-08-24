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
 * silently discarded — the bot gets no signal that it was caught.
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
