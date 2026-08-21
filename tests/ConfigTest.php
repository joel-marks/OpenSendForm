<?php

declare(strict_types=1);

namespace OpenSendForm\Tests;

use OpenSendForm\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testUsesDefaultsWhenEnvironmentEmpty(): void
    {
        $config = Config::fromEnvironment([]);

        self::assertSame('production', $config->appEnv());
        self::assertSame('localhost', $config->smtpHost());
        self::assertSame(25, $config->smtpPort());
        self::assertStringStartsWith('sqlite:', $config->dbDsn());
        self::assertStringContainsString(
            'var/data/opensendform.sqlite',
            $config->dbDsn()
        );
    }

    public function testEnvironmentOverridesDefaults(): void
    {
        $config = Config::fromEnvironment([
            'APP_ENV'   => 'testing',
            'SMTP_HOST' => 'mailpit',
            'SMTP_PORT' => '1025',
            'DB_DSN'    => 'mysql:host=db;dbname=osf',
        ]);

        self::assertSame('testing', $config->appEnv());
        self::assertSame('mailpit', $config->smtpHost());
        self::assertSame(1025, $config->smtpPort());
        self::assertSame('mysql:host=db;dbname=osf', $config->dbDsn());
    }

    public function testBlankAndFalseValuesFallBackToDefaults(): void
    {
        $config = Config::fromEnvironment([
            'SMTP_HOST' => '',
            'SMTP_PORT' => false,
        ]);

        // Empty string and false do not override the shipped defaults.
        self::assertSame('localhost', $config->smtpHost());
        self::assertSame(25, $config->smtpPort());
    }

    public function testUnknownKeyThrows(): void
    {
        $config = Config::fromEnvironment([]);

        $this->expectException(\InvalidArgumentException::class);
        $config->get('NOPE');
    }
}
