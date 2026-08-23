<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Form;

use OpenSendForm\Form\FormKey;
use PHPUnit\Framework\TestCase;

final class FormKeyTest extends TestCase
{
    public function testFormatIsPrefixPlus32HexChars(): void
    {
        $key = FormKey::generate();

        self::assertMatchesRegularExpression('/^osf_[0-9a-f]{32}$/', $key);
    }

    public function testGeneratesUniqueKeysAcrossManyCalls(): void
    {
        $keys = [];
        for ($i = 0; $i < 1000; $i++) {
            $key = FormKey::generate();
            self::assertMatchesRegularExpression('/^osf_[0-9a-f]{32}$/', $key);
            $keys[$key] = true;
        }

        // No collisions: 1000 distinct keys.
        self::assertCount(1000, $keys);
    }
}
