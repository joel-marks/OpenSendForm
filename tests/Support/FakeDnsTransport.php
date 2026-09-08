<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Support;

use OpenSendForm\Validation\DnsTransport;

/**
 * A scripted DnsTransport for BoundedDnsChecker tests: no sockets, fully
 * deterministic. Answers are programmed per record type (MX=15, A=1); each
 * answer is one of:
 *   ['records' => N]  -> NOERROR with N answers
 *   ['nxdomain']      -> NXDOMAIN
 *   ['servfail']      -> SERVFAIL (inconclusive)
 *   ['truncated']     -> NOERROR, TC set, 0 answers (inconclusive)
 *   ['timeout']       -> returns null (as a real read timeout would)
 * A programmed 'delay' (seconds) sleeps before replying, so the total-bound
 * behaviour can be exercised against real wall-clock.
 *
 * Every response echoes the query id from the packet so the checker's id-match
 * succeeds, exactly like a real resolver.
 */
final class FakeDnsTransport implements DnsTransport
{
    /** @var array<int, array<string, mixed>> keyed by record type */
    private array $answers;
    private float $delay;

    /** @var array<int, array{nameserver: string, type: int, timeout: float}> */
    public array $calls = [];

    /**
     * @param array<int, array<string, mixed>> $answers
     */
    public function __construct(array $answers, float $delay = 0.0)
    {
        $this->answers = $answers;
        $this->delay = $delay;
    }

    public function exchange(string $nameserver, string $packet, float $timeout): ?string
    {
        $type = self::qtype($packet);
        $this->calls[] = ['nameserver' => $nameserver, 'type' => $type, 'timeout' => $timeout];

        if ($this->delay > 0.0) {
            // Simulate a resolver that consumes (up to) the whole budget.
            usleep((int) round(min($this->delay, $timeout) * 1_000_000));
        }

        $answer = $this->answers[$type] ?? ['timeout' => true];

        if (isset($answer['timeout'])) {
            return null;
        }

        $id = self::queryId($packet);

        if (isset($answer['nxdomain'])) {
            return self::header($id, rcode: 3, answers: 0, truncated: false);
        }
        if (isset($answer['servfail'])) {
            return self::header($id, rcode: 2, answers: 0, truncated: false);
        }
        if (isset($answer['truncated'])) {
            return self::header($id, rcode: 0, answers: 0, truncated: true);
        }

        $records = (int) ($answer['records'] ?? 0);

        return self::header($id, rcode: 0, answers: $records, truncated: false);
    }

    private static function queryId(string $packet): int
    {
        $parsed = unpack('nid', substr($packet, 0, 2));

        return $parsed === false ? 0 : $parsed['id'];
    }

    private static function qtype(string $packet): int
    {
        // QTYPE is the 2 bytes before the trailing QCLASS (last 4 bytes).
        $parsed = unpack('ntype', substr($packet, -4, 2));

        return $parsed === false ? 0 : $parsed['type'];
    }

    private static function header(int $id, int $rcode, int $answers, bool $truncated): string
    {
        $flags = 0x8000 | ($rcode & 0x000F); // QR set + rcode
        if ($truncated) {
            $flags |= 0x0200;
        }

        return pack('n6', $id & 0xFFFF, $flags, 1, $answers, 0, 0);
    }
}
