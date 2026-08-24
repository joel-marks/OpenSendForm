<?php

declare(strict_types=1);

namespace OpenSendForm\Validation;

/**
 * Real DNS checker using the system resolver.
 *
 * A domain that publishes MX records accepts mail directly. Per RFC 5321
 * a domain with no MX but an A/AAAA record is still a valid mail
 * destination (the address record is used as an implicit MX), so we fall
 * back to an A lookup before rejecting.
 */
final class SystemDnsChecker implements DnsChecker
{
    public function domainAcceptsMail(string $domain): bool
    {
        if ($domain === '') {
            return false;
        }

        return checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A');
    }
}
