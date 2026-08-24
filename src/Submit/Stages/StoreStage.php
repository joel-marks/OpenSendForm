<?php

declare(strict_types=1);

namespace OpenSendForm\Submit\Stages;

use OpenSendForm\Submission\SubmissionRepository;
use OpenSendForm\Submit\Stage;
use OpenSendForm\Submit\SubmitContext;
use OpenSendForm\Submit\SubmitOutcome;

/**
 * Stage (i): store the submission.
 *
 * User fields (reserved _osf_* already removed) are serialised to JSON and
 * handed to the repository, which always persists them as the in-flight
 * delivery payload (metadata is always recorded too); the delivery service
 * clears content after a successful send unless the form's store_content
 * toggle is on. Status is 'received'; the delivery stage that follows may
 * advance it. The new row's id is stashed on the context so that stage
 * knows what to send.
 *
 * This stage does not terminate the pipeline: it returns null so the always
 * present delivery stage runs next and produces the success outcome.
 */
final class StoreStage implements Stage
{
    private SubmissionRepository $submissions;

    public function __construct(SubmissionRepository $submissions)
    {
        $this->submissions = $submissions;
    }

    public function process(SubmitContext $context): ?SubmitOutcome
    {
        $form = $context->form ?? [];
        $contentJson = json_encode($context->userFields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $context->submissionId = $this->submissions->recordSubmission(
            (int) $form['id'],
            $context->remoteIp,
            $context->matchedOrigin,
            $context->userAgent,
            $contentJson,
            'received'
        );

        return null;
    }
}
