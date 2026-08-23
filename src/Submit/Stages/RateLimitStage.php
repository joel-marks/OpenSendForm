<?php

declare(strict_types=1);

namespace OpenSendForm\Submit\Stages;

use OpenSendForm\Config;
use OpenSendForm\RateLimit\RateLimiter;
use OpenSendForm\Submit\Stage;
use OpenSendForm\Submit\SubmitContext;
use OpenSendForm\Submit\SubmitOutcome;

/**
 * Stage (e): rate limits.
 *
 * Per-IP (per minute) is checked first, then per-form (per hour). Either
 * limit being exceeded returns rate_limited. The IP is taken from
 * REMOTE_ADDR only; honouring a trusted proxy's forwarded-for header is a
 * future configuration concern.
 */
final class RateLimitStage implements Stage
{
    private RateLimiter $limiter;
    private Config $config;

    public function __construct(RateLimiter $limiter, Config $config)
    {
        $this->limiter = $limiter;
        $this->config = $config;
    }

    public function process(SubmitContext $context): ?SubmitOutcome
    {
        $ipBucket = 'ip:' . $context->remoteIp;
        if (!$this->limiter->hit($ipBucket, $this->config->rateIpPerMinute(), 60)) {
            return $this->limited();
        }

        $formKey = (string) ($context->form['form_key'] ?? '');
        $formBucket = 'form:' . $formKey;
        if (!$this->limiter->hit($formBucket, $this->config->rateFormPerHour(), 3600)) {
            return $this->limited();
        }

        return null;
    }

    private function limited(): SubmitOutcome
    {
        return SubmitOutcome::error(429, 'rate_limited', 'Too many submissions. Please try again later.');
    }
}
