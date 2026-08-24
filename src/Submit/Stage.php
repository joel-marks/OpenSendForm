<?php

declare(strict_types=1);

namespace OpenSendForm\Submit;

/**
 * One step in the submission pipeline.
 *
 * A stage inspects/updates the shared context and returns null to let the
 * pipeline continue, or a SubmitOutcome to short-circuit and produce the
 * response immediately.
 */
interface Stage
{
    public function process(SubmitContext $context): ?SubmitOutcome;
}
