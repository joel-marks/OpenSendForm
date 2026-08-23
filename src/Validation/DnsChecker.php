<?php

declare(strict_types=1);

namespace OpenSendForm\Validation;

/**
 * Decides whether a domain is plausibly able to receive mail.
 *
 * Abstracted behind an interface so tests can substitute a deterministic
 * fake and never touch real DNS.
 */
interface DnsChecker
{
    /**
     * True if the domain has an MX record, or (falling back) an A record.
     */
    public function domainAcceptsMail(string $domain): bool;
}
