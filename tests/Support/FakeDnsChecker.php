<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Support;

use OpenSendForm\Validation\DnsChecker;

/**
 * A DNS checker that never touches the network. It returns a configurable
 * default and allows per-domain overrides so tests can simulate a domain
 * that does (or does not) accept mail.
 */
final class FakeDnsChecker implements DnsChecker
{
    private bool $default;

    /** @var array<string, bool> */
    private array $overrides = [];

    public function __construct(bool $default = true)
    {
        $this->default = $default;
    }

    public function setDomain(string $domain, bool $accepts): void
    {
        $this->overrides[strtolower($domain)] = $accepts;
    }

    public function domainAcceptsMail(string $domain): bool
    {
        return $this->overrides[strtolower($domain)] ?? $this->default;
    }
}
