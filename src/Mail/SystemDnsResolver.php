<?php

declare(strict_types=1);

namespace OpenSendForm\Mail;

/**
 * Real DNS resolver backed by the system's dns_get_record().
 *
 * Offline-safe: any failure (no network, SERVFAIL, a resolver that returns
 * false) is swallowed and reported as "no records", so the deliverability
 * checker degrades to "could not verify" rather than erroring. A TXT record
 * can be split into several strings on the wire; PHP hands those back joined
 * in 'txt' (and split in 'entries'), and we return the joined form.
 */
final class SystemDnsResolver implements DnsResolver
{
    public function txtRecords(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return [];
        }

        // Suppress warnings: on an offline host dns_get_record emits a warning
        // and returns false, which we treat as simply "nothing found".
        $records = @dns_get_record($name, DNS_TXT);
        if (!is_array($records)) {
            return [];
        }

        $out = [];
        foreach ($records as $record) {
            if (isset($record['txt']) && is_string($record['txt'])) {
                $out[] = $record['txt'];
            }
        }

        return $out;
    }
}
