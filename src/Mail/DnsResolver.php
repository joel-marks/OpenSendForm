<?php

declare(strict_types=1);

namespace OpenSendForm\Mail;

/**
 * Reads DNS TXT records for a name.
 *
 * Deliberately narrow: the deliverability checker only ever needs TXT lookups
 * (SPF, DKIM, DMARC all live in TXT records). Behind an interface so the real
 * resolver can be swapped for a scriptable fake — tests never touch the
 * network, and a lookup that fails or times out (e.g. the server is offline)
 * simply yields an empty list rather than throwing.
 */
interface DnsResolver
{
    /**
     * Every TXT record published at $name, each as a single assembled string.
     * Returns [] when the name has no TXT records, or the lookup could not be
     * performed at all (offline, resolver error). Callers treat "no records"
     * and "could not look up" the same way: nothing found.
     *
     * @return array<int, string>
     */
    public function txtRecords(string $name): array;
}
