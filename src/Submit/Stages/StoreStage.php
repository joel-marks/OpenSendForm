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
 * The terminal stage. User fields (reserved _osf_* already removed) are
 * serialised to JSON and handed to the repository, which persists the
 * content column only when the form's store_content toggle is on; metadata
 * is always recorded. Status is 'received' — mail relay arrives in a later
 * increment.
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

        $this->submissions->recordSubmission(
            (int) $form['id'],
            $context->remoteIp,
            $context->matchedOrigin,
            $context->userAgent,
            $contentJson,
            'received'
        );

        return SubmitOutcome::success();
    }
}
