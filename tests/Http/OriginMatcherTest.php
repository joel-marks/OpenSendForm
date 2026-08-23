<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Http;

use OpenSendForm\Http\OriginMatcher;
use PHPUnit\Framework\TestCase;

final class OriginMatcherTest extends TestCase
{
    public function testNormaliseReducesToSchemeHostPort(): void
    {
        self::assertSame('https://example.com', OriginMatcher::normalise('HTTPS://Example.COM'));
        self::assertSame('http://localhost:8080', OriginMatcher::normalise('http://localhost:8080'));
        // A Referer-style full URL is reduced to its origin.
        self::assertSame('https://example.com', OriginMatcher::normalise('https://example.com/contact?x=1#f'));
    }

    public function testNormaliseRejectsUnusableValues(): void
    {
        self::assertNull(OriginMatcher::normalise(null));
        self::assertNull(OriginMatcher::normalise(''));
        self::assertNull(OriginMatcher::normalise('null'));
        self::assertNull(OriginMatcher::normalise('example.com')); // no scheme
        self::assertNull(OriginMatcher::normalise('ftp://example.com')); // wrong scheme
    }

    public function testResolvePrefersOriginThenFallsBackToReferer(): void
    {
        self::assertSame(
            'https://example.com',
            OriginMatcher::resolve('https://example.com', 'https://other.com/page')
        );

        // No Origin header -> derive from the Referer URL.
        self::assertSame(
            'https://other.com',
            OriginMatcher::resolve(null, 'https://other.com/page')
        );

        self::assertNull(OriginMatcher::resolve(null, null));
    }

    public function testMatchReturnsOriginOnlyWhenAllowlisted(): void
    {
        $allowed = ['https://example.com', 'http://localhost:8080'];

        self::assertSame(
            'https://example.com',
            OriginMatcher::match('https://example.com', null, $allowed)
        );
        self::assertNull(OriginMatcher::match('https://evil.com', null, $allowed));
        self::assertNull(OriginMatcher::match(null, null, $allowed));
    }
}
