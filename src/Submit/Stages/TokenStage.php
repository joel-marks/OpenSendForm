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
 * On the JSON path (fetch/API callers) a missing, forged (INVALID) or
 * too-young token returns a silent success and the submission is discarded —
 * these are bot signatures and must not be revealed. The one honest failure
 * is an authentic but EXPIRED token: a real user who left the page open too
 * long gets token_expired so the client can refresh and retry.
 *
 * On the HTML-negotiated path (a native no-JS browser POST — the token is
 * fetched and injected by osf.js, so a plain form never carries one) the
 * policy is per-form (`forms.allow_nojs`):
 *  - allow_nojs = 0 (default): a missing/invalid/too-young token gets the
 *    HONEST "this form requires JavaScript" error — never the fake-success
 *    page, and nothing is stored. A silent fake success on a full-page
 *    navigation would tell the submitter their message was sent when it
 *    was not, which is worse than an honest failure.
 *  - allow_nojs = 1: a *missing* token is let through (the min-time bot
 *    check is knowingly waived for this subset) so a genuine no-JS
 *    submission can be stored and delivered. A present-but-invalid/too-young
 *    token on this path is still treated as a bot signature and falls
 *    through to the ordinary silent-success/discard.
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
        $allowNojs = (int) ($context->form['allow_nojs'] ?? 0) === 1;

        if ($token === null || $token === '') {
            if ($context->prefersHtml) {
                if ($allowNojs) {
                    return null;
                }

                return self::javascriptRequired();
            }

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
                if ($context->prefersHtml && !$allowNojs) {
                    return self::javascriptRequired();
                }

                return SubmitOutcome::success();
        }
    }

    private static function javascriptRequired(): SubmitOutcome
    {
        return SubmitOutcome::error(
            400,
            'javascript_required',
            'This form requires JavaScript to submit. Your message was not sent.'
        );
    }
}
