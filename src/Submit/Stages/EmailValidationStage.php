<?php

declare(strict_types=1);

namespace OpenSendForm\Submit\Stages;

use OpenSendForm\Submit\Stage;
use OpenSendForm\Submit\SubmitContext;
use OpenSendForm\Submit\SubmitOutcome;
use OpenSendForm\Validation\DnsChecker;

/**
 * Stage (h): email field validation.
 *
 * Only runs when the submission carries a field literally named "email".
 * Its syntax is checked first (invalid_email), then its domain is checked
 * for a mail-capable DNS record (email_domain_invalid). The endpoint never
 * requires the email field to be present; whether a form needs it is the
 * form owner's HTML concern.
 */
final class EmailValidationStage implements Stage
{
    private DnsChecker $dns;

    public function __construct(DnsChecker $dns)
    {
        $this->dns = $dns;
    }

    public function process(SubmitContext $context): ?SubmitOutcome
    {
        $email = $context->userFields['email'] ?? null;
        if ($email === null) {
            return null;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return SubmitOutcome::error(400, 'invalid_email', 'The email address is not valid.');
        }

        $at = strrpos($email, '@');
        $domain = $at === false ? '' : substr($email, $at + 1);

        if (!$this->dns->domainAcceptsMail($domain)) {
            return SubmitOutcome::error(400, 'email_domain_invalid', 'The email domain cannot receive mail.');
        }

        return null;
    }
}
