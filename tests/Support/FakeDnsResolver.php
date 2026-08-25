<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Support;

use OpenSendForm\Mail\DnsResolver;

/**
 * A TXT resolver that never touches the network. Names are scripted with the
 * TXT records they should return; an unknown name returns [] (nothing found),
 * exactly as the real resolver does for a missing record or an offline host.
 */
final class FakeDnsResolver implements DnsResolver
{
    /** @var array<string, array<int, string>> */
    private array $records = [];

    /**
     * Script the TXT records for a name (case-insensitive match).
     *
     * @param array<int, string> $txt
     */
    public function setTxt(string $name, array $txt): void
    {
        $this->records[strtolower(trim($name))] = array_values($txt);
    }

    public function txtRecords(string $name): array
    {
        return $this->records[strtolower(trim($name))] ?? [];
    }
}
