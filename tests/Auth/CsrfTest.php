<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Auth;

use OpenSendForm\Auth\Csrf;
use OpenSendForm\Tests\Support\FakeSession;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    public function testTokenIsStableWithinSession(): void
    {
        $csrf = new Csrf(new FakeSession());

        self::assertSame($csrf->token(), $csrf->token());
    }

    public function testValidatesMatchingToken(): void
    {
        $csrf = new Csrf(new FakeSession());

        self::assertTrue($csrf->validate($csrf->token()));
    }

    public function testRejectsWrongToken(): void
    {
        $csrf = new Csrf(new FakeSession());
        $csrf->token();

        self::assertFalse($csrf->validate('not-the-token'));
    }

    public function testRejectsMissingToken(): void
    {
        $csrf = new Csrf(new FakeSession());
        $csrf->token();

        self::assertFalse($csrf->validate(null));
        self::assertFalse($csrf->validate(''));
    }

    public function testRejectsWhenNoTokenIssuedYet(): void
    {
        // Never called token(): nothing stored, so any candidate fails closed.
        $csrf = new Csrf(new FakeSession());

        self::assertFalse($csrf->validate('anything'));
    }
}
