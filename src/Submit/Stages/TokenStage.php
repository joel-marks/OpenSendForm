<?php

declare(strict_types=1);

namespace OpenSendForm\Submit\Stages;

use OpenSendForm\Security\SubmitToken;
use OpenSendForm\Submit\Stage;
use OpenSendForm\Submit\SubmitContext;
use OpenSendForm\Submit\SubmitOutcome;

/**
 * Stage (g): submit-token check.
 *
 * A missing, forged (INVALID) or too-young token returns a silent success
 * and the submission is discarded — these are bot signatures and must not
 * be revealed. The one honest failure is an authentic but EXPIRED token:
 * a real user who left the page open too long gets token_expired so the
 * client can refresh the token and retry.
 */
final class TokenStage implements Stage
{
    private SubmitToken $tokens;

    public function __construct(SubmitToken $tokens)
    {
        $this->tokens = $tokens;
    }

    public function process(SubmitContext $context): ?SubmitOutcome
    {
        $token = $context->token;
        if ($token === null || $token === '') {
            return SubmitOutcome::success();
        }

        $formKey = (string) ($context->form['form_key'] ?? '');
        $status = $this->tokens->verify($token, $formKey);

        switch ($status) {
            case SubmitToken::VALID:
                return null;

            case SubmitToken::EXPIRED:
                return SubmitOutcome::error(400, 'token_expired', 'The form token has expired. Please retry.');

            case SubmitToken::TOO_YOUNG:
            case SubmitToken::INVALID:
            default:
                return SubmitOutcome::success();
        }
    }
}
