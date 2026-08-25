<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Mail;

use OpenSendForm\Mail\DeliverabilityChecker;
use OpenSendForm\Tests\Support\FakeDnsResolver;
use PHPUnit\Framework\TestCase;

/**
 * The SPF/DKIM/DMARC checker, driven entirely through the scriptable
 * FakeDnsResolver so no lookup ever touches the network. Covers present /
 * absent / malformed states and the exact recommended-record text.
 */
final class DeliverabilityCheckerTest extends TestCase
{
    private FakeDnsResolver $dns;

    protected function setUp(): void
    {
        $this->dns = new FakeDnsResolver();
    }

    private function checker(): DeliverabilityChecker
    {
        return new DeliverabilityChecker($this->dns);
    }

    // --- SPF --------------------------------------------------------------

    public function testSpfPresentIsGreenAndReturnsTheRecord(): void
    {
        $this->dns->setTxt('example.com', ['v=spf1 include:_spf.host.com ~all']);

        $spf = $this->checker()->check('hello@example.com', 'admin@example.com')['spf'];

        self::assertTrue($spf['ok']);
        self::assertSame('present', $spf['state']);
        self::assertSame('v=spf1 include:_spf.host.com ~all', $spf['found']);
    }

    public function testSpfAbsentIsAmberWithRecommendedRecord(): void
    {
        // No TXT at the domain at all.
        $spf = $this->checker()->check('hello@example.com', 'admin@example.com', 'default', 'mail.hostgator.com')['spf'];

        self::assertFalse($spf['ok']);
        self::assertSame('absent', $spf['state']);
        self::assertNull($spf['found']);
        // The SMTP host's registrable domain seeds the include hint.
        self::assertSame('v=spf1 a mx include:hostgator.com ~all', $spf['recommended']);
    }

    public function testSpfMalformedRecordCountsAsAbsent(): void
    {
        // A TXT exists but is not an SPF record (no v=spf1 prefix).
        $this->dns->setTxt('example.com', ['google-site-verification=abc123']);

        $spf = $this->checker()->check('hello@example.com', 'admin@example.com')['spf'];

        self::assertFalse($spf['ok']);
        self::assertSame('absent', $spf['state']);
    }

    public function testSpfRecommendationWithoutUsableSmtpHostOmitsInclude(): void
    {
        $spf = $this->checker()->check('hello@example.com', 'admin@example.com', 'default', 'localhost')['spf'];
        self::assertSame('v=spf1 a mx ~all', $spf['recommended']);

        $spfNoHost = $this->checker()->check('hello@example.com', 'admin@example.com', 'default', '')['spf'];
        self::assertSame('v=spf1 a mx ~all', $spfNoHost['recommended']);
    }

    // --- DKIM -------------------------------------------------------------

    public function testDkimFoundAtSelector(): void
    {
        $this->dns->setTxt('default._domainkey.example.com', ['v=DKIM1; k=rsa; p=MIGfMA0BiQ']);

        $dkim = $this->checker()->check('hello@example.com', 'admin@example.com', 'default')['dkim'];

        self::assertTrue($dkim['ok']);
        self::assertSame('found', $dkim['state']);
        self::assertSame('default._domainkey', $dkim['name']);
    }

    public function testDkimNotFoundForMissingSelector(): void
    {
        $this->dns->setTxt('default._domainkey.example.com', ['v=DKIM1; k=rsa; p=key']);

        // A different selector has no record.
        $dkim = $this->checker()->check('hello@example.com', 'admin@example.com', 's1')['dkim'];

        self::assertFalse($dkim['ok']);
        self::assertSame('not_found', $dkim['state']);
        self::assertSame('s1._domainkey', $dkim['name']);
    }

    public function testDkimSelectorIsNormalisedAndDefaulted(): void
    {
        $report = $this->checker()->check('hello@example.com', 'admin@example.com', 'BAD SELECTOR!');
        // Invalid selectors fall back to 'default'.
        self::assertSame('default', $report['selector']);
    }

    // --- DMARC ------------------------------------------------------------

    public function testDmarcPresentIsGreen(): void
    {
        $this->dns->setTxt('_dmarc.example.com', ['v=DMARC1; p=reject; rua=mailto:dmarc@example.com']);

        $dmarc = $this->checker()->check('hello@example.com', 'admin@example.com')['dmarc'];

        self::assertTrue($dmarc['ok']);
        self::assertSame('present', $dmarc['state']);
    }

    public function testDmarcAbsentRecommendsStarterWithAdminRua(): void
    {
        $dmarc = $this->checker()->check('hello@example.com', 'boss@example.com')['dmarc'];

        self::assertFalse($dmarc['ok']);
        self::assertSame('_dmarc', $dmarc['name']);
        self::assertSame('v=DMARC1; p=none; rua=mailto:boss@example.com', $dmarc['recommended']);
    }

    public function testDmarcRecommendationOmitsRuaWhenAdminEmailInvalid(): void
    {
        $dmarc = $this->checker()->check('hello@example.com', 'not-an-email')['dmarc'];
        self::assertSame('v=DMARC1; p=none', $dmarc['recommended']);
    }

    // --- Domain handling --------------------------------------------------

    public function testInvalidFromAddressMakesReportInvalidAndSkipsLookups(): void
    {
        $report = $this->checker()->check('not-an-email', 'admin@example.com');

        self::assertFalse($report['valid']);
        self::assertSame('', $report['domain']);
        // Absent everywhere, but no crash and no record text needing a domain.
        self::assertFalse($report['spf']['ok']);
        self::assertFalse($report['dkim']['ok']);
        self::assertFalse($report['dmarc']['ok']);
    }

    public function testDomainOfExtractsAndLowercases(): void
    {
        self::assertSame('example.com', DeliverabilityChecker::domainOf('Hello@Example.COM'));
        self::assertSame('', DeliverabilityChecker::domainOf('garbage'));
        self::assertSame('', DeliverabilityChecker::domainOf(''));
    }

    public function testDomainUsedForLookupsIsTheFromDomain(): void
    {
        $this->dns->setTxt('sub.example.co.uk', ['v=spf1 ~all']);

        $spf = $this->checker()->check('hi@sub.example.co.uk', 'admin@x.com')['spf'];
        self::assertTrue($spf['ok']);
    }
}
