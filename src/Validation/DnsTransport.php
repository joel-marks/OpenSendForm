<?php

declare(strict_types=1);

namespace OpenSendForm\Validation;

/**
 * A single, bounded DNS exchange: send one query datagram to one nameserver
 * and wait, at most $timeout seconds, for its reply.
 *
 * The seam exists so BoundedDnsChecker can be unit-tested with a scripted
 * transport (deterministic answers, timeouts, latency) without touching a real
 * resolver — the production UdpDnsTransport is the only network-bound part.
 */
interface DnsTransport
{
    /**
     * @param string $nameserver Resolver IP address (no port).
     * @param string $packet     A ready-to-send DNS query packet.
     * @param float  $timeout    Hard upper bound, in seconds, for the reply.
     * @return string|null The raw response bytes, or null on timeout/error.
     */
    public function exchange(string $nameserver, string $packet, float $timeout): ?string;
}
