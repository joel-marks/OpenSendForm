<?php

declare(strict_types=1);

namespace OpenSendForm\Submit\Stages;

use OpenSendForm\Http\OriginMatcher;
use OpenSendForm\Submit\Stage;
use OpenSendForm\Submit\SubmitContext;
use OpenSendForm\Submit\SubmitOutcome;

/**
 * Stage (d): origin check.
 *
 * The request's Origin (or, absent that, the Referer host) must appear in
 * the form's allowlist. On success the matched origin is recorded so the
 * response can carry CORS headers; on failure the request is refused with
 * origin_not_allowed and no CORS headers are emitted.
 */
final class OriginStage implements Stage
{
    public function process(SubmitContext $context): ?SubmitOutcome
    {
        /** @var array<int, string> $allowed */
        $allowed = $context->form['allowed_origins'] ?? [];

        $matched = OriginMatcher::match(
            $context->originHeader,
            $context->refererHeader,
            $allowed
        );

        if ($matched === null) {
            return SubmitOutcome::error(403, 'origin_not_allowed', 'Origin is not allowed for this form.');
        }

        $context->matchedOrigin = $matched;

        return null;
    }
}
