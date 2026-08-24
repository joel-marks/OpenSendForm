<?php

declare(strict_types=1);

namespace OpenSendForm\Submit\Stages;

use OpenSendForm\Config;
use OpenSendForm\Mail\DeliveryService;
use OpenSendForm\Submit\Stage;
use OpenSendForm\Submit\SubmitContext;
use OpenSendForm\Submit\SubmitOutcome;

/**
 * Stage (j): synchronous-first mail delivery.
 *
 * The terminal stage. It makes at most one in-request send attempt for the
 * just-stored submission and then ALWAYS returns success — the submission is
 * safely stored, so a delivery failure must never change the submitter's
 * {"ok":true}. Any failure is caught here (the delivery service already
 * records failures and schedules retries; this catch is belt-and-braces).
 *
 * Delivery is skipped entirely, leaving the submission at 'received', when:
 *  - no mailer is configured (no DeliveryService wired), or
 *  - SMTP_HOST is empty/unconfigured (storage-only operation), or
 *  - no submission was stored (an earlier stage short-circuited).
 */
final class DeliveryStage implements Stage
{
    private ?DeliveryService $delivery;
    private Config $config;

    public function __construct(?DeliveryService $delivery, Config $config)
    {
        $this->delivery = $delivery;
        $this->config = $config;
    }

    public function process(SubmitContext $context): ?SubmitOutcome
    {
        if ($this->shouldAttempt($context)) {
            try {
                /** @var int $id */
                $id = $context->submissionId;
                $this->delivery->attemptDelivery($id);
            } catch (\Throwable $e) {
                // Never let a delivery problem break the submitter's response.
            }
        }

        return SubmitOutcome::success();
    }

    private function shouldAttempt(SubmitContext $context): bool
    {
        return $this->delivery !== null
            && $context->submissionId !== null
            && trim($this->config->smtpHost()) !== '';
    }
}
