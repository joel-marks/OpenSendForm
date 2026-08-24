<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Auth;

use InvalidArgumentException;
use OpenSendForm\Auth\Base32;
use PHPUnit\Framework\TestCase;

final class Base32Test extends TestCase
{
    /**
     * RFC 4648 section 10 test vectors. encode() emits no padding, so the
     * expected strings are the RFC values with '=' padding stripped.
     *
     * @return array<string, array{string, string}>
     */
    public static function rfc4648Vectors(): array
    {
        return [
            'empty'  => ['', ''],
            'f'      => ['f', 'MY'],
            'fo'     => ['fo', 'MZXQ'],
            'foo'    => ['foo', 'MZXW6'],
            'foob'   => ['foob', 'MZXW6YQ'],
            'fooba'  => ['fooba', 'MZXW6YTB'],
            'foobar' => ['foobar', 'MZXW6YTBOI'],
        ];
    }

    /**
     * @dataProvider rfc4648Vectors
     */
    public function testEncodeMatchesRfcVectorsWithoutPadding(string $plain, string $encoded): void
    {
        self::assertSame($encoded, Base32::encode($plain));
    }

    /**
     * @dataProvider rfc4648Vectors
     */
    public function testDecodeAcceptsUnpaddedInput(string $plain, string $encoded): void
    {
        self::assertSame($plain, Base32::decode($encoded));
    }

    /**
     * @dataProvider rfc4648Vectors
     */
    public function testDecodeAcceptsPaddedInput(string $plain, string $encoded): void
    {
        // Pad back up to a multiple of 8 symbols with '=', as canonical RFC
        // output would be; decode must accept it.
        $padded = str_pad($encoded, (int) (ceil(strlen($encoded) / 8) * 8), '=');

        self::assertSame($plain, Base32::decode($padded));
    }

    public function testRoundTripsRandomBytes(): void
    {
        self::assertSame('', Base32::decode(Base32::encode('')));
        for ($length = 1; $length <= 32; $length++) {
            $bytes = random_bytes($length);
            self::assertSame($bytes, Base32::decode(Base32::encode($bytes)));
        }
    }

    public function testDecodeIsCaseInsensitiveAndToleratesWhitespace(): void
    {
        self::assertSame('foobar', Base32::decode(" mzxw6y tbOI\n"));
    }

    public function testDecodeRejectsInvalidCharacter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Base32::decode('MZXW6!');
    }
}
