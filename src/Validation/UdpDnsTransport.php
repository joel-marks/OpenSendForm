<?php

declare(strict_types=1);

namespace OpenSendForm\Validation;

/**
 * Production DnsTransport: one bounded UDP query to a resolver.
 *
 * A fresh connectionless UDP socket per exchange, with both a connect timeout
 * and a read timeout, so a slow or silent resolver can never block longer than
 * the caller's budget. Any socket error or read timeout yields null, which
 * BoundedDnsChecker treats as "inconclusive" and fails open.
 */
final class UdpDnsTransport implements DnsTransport
{
    public function exchange(string $nameserver, string $packet, float $timeout): ?string
    {
        if ($timeout <= 0.0) {
            return null;
        }

        // Bracket IPv6 literals for the udp:// URL; IPv4 passes through.
        $host = str_contains($nameserver, ':') ? '[' . $nameserver . ']' : $nameserver;

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client('udp://' . $host . ':53', $errno, $errstr, $timeout);
        if ($socket === false) {
            return null;
        }

        $seconds = (int) floor($timeout);
        $micros = (int) round(($timeout - $seconds) * 1_000_000);
        stream_set_timeout($socket, $seconds, $micros);

        if (@fwrite($socket, $packet) === false) {
            fclose($socket);

            return null;
        }

        // A DNS/UDP answer fits comfortably in 4 KB (we only read the header).
        $response = @fread($socket, 4096);
        $meta = stream_get_meta_data($socket);
        fclose($socket);

        if (($meta['timed_out'] ?? false) === true) {
            return null;
        }
        if (!is_string($response) || $response === '') {
            return null;
        }

        return $response;
    }
}
