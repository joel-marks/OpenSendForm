<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Validation;

use OpenSendForm\Tests\Support\FakeDnsTransport;
use OpenSendForm\Validation\BoundedDnsChecker;
use PHPUnit\Framework\TestCase;

/**
 * BoundedDnsChecker: the MX-then-A logic, the fail-open policy on anything
 * inconclusive, and the hard total time bound. A scripted transport keeps the
 * record logic deterministic; the timeout tests use real wall-clock against a
 * transport that consumes its budget.
 */
final class BoundedDnsCheckerTest extends TestCase
{
    private const NS = ['192.0.2.53'];

    public function testMxRecordAccepts(): void
    {
        $checker = $this->checker(new FakeDnsTransport([15 => ['records' => 2]]));
        self::assertTrue($checker->domainAcceptsMail('example.com'));
    }

    public function testFallsBackToAWhenNoMx(): void
    {
        $transport = new FakeDnsTransport([15 => ['records' => 0], 1 => ['records' => 1]]);
        $checker = $this->checker($transport);

        self::assertTrue($checker->domainAcceptsMail('example.com'));
        // MX asked first, then A.
        self::assertSame([15, 1], array_column($transport->calls, 'type'));
    }

    public function testAuthoritativeNegativeRejects(): void
    {
        // NOERROR but neither MX nor A records: a definitive "cannot receive".
        $transport = new FakeDnsTransport([15 => ['records' => 0], 1 => ['records' => 0]]);
        self::assertFalse($this->checker($transport)->domainAcceptsMail('no-mail.example'));
    }

    public function testNxdomainRejects(): void
    {
        $transport = new FakeDnsTransport([15 => ['nxdomain' => true], 1 => ['nxdomain' => true]]);
        self::assertFalse($this->checker($transport)->domainAcceptsMail('nope.invalid'));
    }

    public function testTimeoutFailsOpen(): void
    {
        // The MX query times out: accept and never even ask for A.
        $transport = new FakeDnsTransport([15 => ['timeout' => true]]);
        $checker = $this->checker($transport);

        self::assertTrue($checker->domainAcceptsMail('slow.example'));
        self::assertSame([15], array_column($transport->calls, 'type'));
    }

    public function testServfailFailsOpen(): void
    {
        $transport = new FakeDnsTransport([15 => ['servfail' => true]]);
        self::assertTrue($this->checker($transport)->domainAcceptsMail('broken.example'));
    }

    public function testTruncatedNoErrorIsInconclusiveAndFailsOpen(): void
    {
        $transport = new FakeDnsTransport([15 => ['truncated' => true]]);
        self::assertTrue($this->checker($transport)->domainAcceptsMail('trunc.example'));
    }

    public function testNoConfiguredNameserverFailsOpen(): void
    {
        $checker = new BoundedDnsChecker([], new FakeDnsTransport([15 => ['records' => 0]]), 3.0);
        self::assertTrue($checker->domainAcceptsMail('example.com'));
    }

    public function testEmptyDomainRejects(): void
    {
        self::assertFalse($this->checker(new FakeDnsTransport([]))->domainAcceptsMail(''));
    }

    public function testTotalBoundIsRespected(): void
    {
        // A transport that always burns its whole budget then times out. Even
        // across MX+A and every nameserver, the call must return within the
        // configured bound (plus a small margin) and fail open.
        $transport = new FakeDnsTransport([15 => ['timeout' => true], 1 => ['timeout' => true]], delay: 5.0);
        $checker = new BoundedDnsChecker(['192.0.2.1', '192.0.2.2'], $transport, 0.4);

        $start = microtime(true);
        $accepted = $checker->domainAcceptsMail('slow.example');
        $elapsed = microtime(true) - $start;

        self::assertTrue($accepted, 'a timeout must fail open');
        self::assertLessThan(0.9, $elapsed, "total lookup took {$elapsed}s, exceeding the ~0.4s bound");
    }

    public function testEncodeQueryProducesAValidQuestion(): void
    {
        $packet = BoundedDnsChecker::encodeQuery(0x1234, 'mail.example.com', 15);

        // Header: id, flags=0x0100 (RD), qdcount=1.
        $header = unpack('nid/nflags/nqd', substr($packet, 0, 6));
        self::assertSame(0x1234, $header['id']);
        self::assertSame(0x0100, $header['flags']);
        self::assertSame(1, $header['qd']);

        // QNAME labels then a root null, then QTYPE=15, QCLASS=1.
        self::assertStringContainsString("\x04mail\x07example\x03com\x00", $packet);
        $tail = unpack('ntype/nclass', substr($packet, -4));
        self::assertSame(15, $tail['type']);
        self::assertSame(1, $tail['class']);
    }

    public function testInterpretRejectsMismatchedId(): void
    {
        $response = pack('n6', 0x1111, 0x8000, 1, 5, 0, 0); // 5 answers but wrong id
        self::assertNull(BoundedDnsChecker::interpret($response, 0x2222));
    }

    public function testInterpretReadsAnswerCount(): void
    {
        $withRecords = pack('n6', 0x55, 0x8000, 1, 3, 0, 0);
        $noRecords = pack('n6', 0x55, 0x8000, 1, 0, 0, 0);
        $nxdomain = pack('n6', 0x55, 0x8003, 1, 0, 0, 0);

        self::assertTrue(BoundedDnsChecker::interpret($withRecords, 0x55));
        self::assertFalse(BoundedDnsChecker::interpret($noRecords, 0x55));
        self::assertFalse(BoundedDnsChecker::interpret($nxdomain, 0x55));
    }

    private function checker(FakeDnsTransport $transport): BoundedDnsChecker
    {
        return new BoundedDnsChecker(self::NS, $transport, 3.0);
    }
}
