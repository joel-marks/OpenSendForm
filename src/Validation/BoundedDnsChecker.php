<?php

declare(strict_types=1);

namespace OpenSendForm\Validation;

/**
 * A DNS checker with a hard total time bound.
 *
 * PHP's built-in resolver calls (checkdnsrr/dns_get_record) accept no timeout,
 * so a slow or unreachable domain can hang a request ~16 s while the stub
 * resolver exhausts its own retries. That held real submitters on a spinner.
 * This checker instead speaks DNS directly over a bounded UDP socket, so the
 * whole MX-then-A lookup completes or gives up within a fixed budget
 * (DEFAULT_TIMEOUT seconds total, across every nameserver and both queries).
 *
 * FAIL-OPEN POLICY. The only outcome that rejects an address is a definitive
 * negative answer from the resolver: NOERROR with no MX and no A record, or
 * NXDOMAIN — i.e. "this domain cannot receive mail", exactly as the old
 * checkdnsrr path rejected. Everything inconclusive — a timeout, a socket
 * error, SERVFAIL/REFUSED, a malformed or mismatched reply, or no configured
 * nameserver at all — ACCEPTS the address and lets SMTP delivery be the
 * arbiter. Rejecting a real submitter over slow DNS is worse than relaying one
 * bad address.
 */
final class BoundedDnsChecker implements DnsChecker
{
    /** Total wall-clock budget for one domainAcceptsMail() call. */
    public const DEFAULT_TIMEOUT = 3.0;

    private const TYPE_A = 1;
    private const TYPE_MX = 15;
    private const CLASS_IN = 1;

    private const RCODE_NOERROR = 0;
    private const RCODE_NXDOMAIN = 3;

    /** @var array<int, string> */
    private array $nameservers;
    private DnsTransport $transport;
    private float $timeout;

    /**
     * @param array<int, string> $nameservers Resolver IPs, tried in order.
     */
    public function __construct(
        array $nameservers,
        DnsTransport $transport,
        float $timeout = self::DEFAULT_TIMEOUT
    ) {
        $this->nameservers = array_values(array_filter($nameservers, static fn ($ns) => is_string($ns) && $ns !== ''));
        $this->transport = $transport;
        $this->timeout = $timeout;
    }

    /**
     * Build a checker from the system's /etc/resolv.conf nameservers over a
     * real UDP transport — the production wiring.
     */
    public static function fromSystem(float $timeout = self::DEFAULT_TIMEOUT): self
    {
        return new self(self::systemNameservers(), new UdpDnsTransport(), $timeout);
    }

    public function domainAcceptsMail(string $domain): bool
    {
        if ($domain === '') {
            return false;
        }

        // No resolver to ask: cannot perform a bounded lookup, so fail open.
        if ($this->nameservers === []) {
            return true;
        }

        $deadline = microtime(true) + $this->timeout;

        // A domain with MX records accepts mail directly; per RFC 5321 one with
        // no MX but an A/AAAA record is still a valid destination (implicit MX).
        $mx = $this->lookup($domain, self::TYPE_MX, $deadline);
        if ($mx === true || $mx === null) {
            return true; // has MX, or inconclusive -> fail open
        }

        $a = $this->lookup($domain, self::TYPE_A, $deadline);
        if ($a === null) {
            return true; // inconclusive -> fail open
        }

        return $a === true; // definitive: A present accepts, absent rejects
    }

    /**
     * Resolve one record type within the shared deadline.
     *
     * @return bool|null true = records present, false = definitive none,
     *                   null = inconclusive (timeout/error/odd reply).
     */
    private function lookup(string $domain, int $type, float $deadline): ?bool
    {
        foreach ($this->nameservers as $nameserver) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0.0) {
                return null;
            }

            $queryId = random_int(0, 0xFFFF);
            $packet = self::encodeQuery($queryId, $domain, $type);
            $response = $this->transport->exchange($nameserver, $packet, $remaining);
            if ($response === null) {
                continue; // this resolver timed out/errored; try the next
            }

            $verdict = self::interpret($response, $queryId);
            if ($verdict !== null) {
                return $verdict;
            }
            // Odd/mismatched reply: try another resolver while time remains.
        }

        return null;
    }

    /**
     * Encode a standard recursive query for one name and record type.
     */
    public static function encodeQuery(int $id, string $domain, int $type): string
    {
        // Header: ID, flags=0x0100 (RD set), QDCOUNT=1, AN/NS/AR=0.
        $header = pack('n6', $id & 0xFFFF, 0x0100, 1, 0, 0, 0);

        $qname = '';
        foreach (explode('.', trim($domain, '.')) as $label) {
            $qname .= chr(strlen($label)) . $label;
        }
        $qname .= "\0";

        return $header . $qname . pack('n2', $type, self::CLASS_IN);
    }

    /**
     * Interpret a response header into a tri-state verdict.
     *
     * Only the 12-byte header is needed: the transaction id (must match), the
     * RCODE and the answer count. Anything unexpected returns null so the
     * caller fails open.
     *
     * @return bool|null true = answers present, false = definitive none,
     *                   null = inconclusive.
     */
    public static function interpret(string $response, int $expectedId): ?bool
    {
        if (strlen($response) < 12) {
            return null;
        }

        $header = unpack('nid/nflags/nqd/nan/nns/nar', substr($response, 0, 12));
        if ($header === false) {
            return null;
        }

        if (($header['id'] & 0xFFFF) !== ($expectedId & 0xFFFF)) {
            return null; // reply for a different query
        }

        $flags = $header['flags'];
        if (($flags & 0x8000) === 0) {
            return null; // not a response (QR unset)
        }

        $rcode = $flags & 0x000F;
        $answers = $header['an'];

        if ($rcode === self::RCODE_NXDOMAIN) {
            return false; // domain does not exist -> cannot receive mail
        }

        if ($rcode === self::RCODE_NOERROR) {
            if ($answers > 0) {
                return true;
            }
            // NOERROR with no answers is a definitive "no such record type",
            // UNLESS the reply was truncated (TC), which we cannot trust.
            $truncated = ($flags & 0x0200) !== 0;

            return $truncated ? null : false;
        }

        return null; // SERVFAIL/REFUSED/etc -> inconclusive
    }

    /**
     * Parse resolver IPs from /etc/resolv.conf. Returns [] when the file is
     * unreadable or lists no usable nameserver.
     *
     * @return array<int, string>
     */
    public static function systemNameservers(): array
    {
        $path = '/etc/resolv.conf';
        if (!is_readable($path)) {
            return [];
        }

        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            return [];
        }

        $servers = [];
        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }
            if (preg_match('/^nameserver\s+(\S+)/i', $line, $m) === 1) {
                $servers[] = $m[1];
            }
        }

        return $servers;
    }
}
