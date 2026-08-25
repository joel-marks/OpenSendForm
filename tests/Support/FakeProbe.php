<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Support;

use OpenSendForm\Install\EnvironmentProbe;

/**
 * A scriptable EnvironmentProbe for driving every branch of the requirements
 * check without touching the real host. Defaults describe a healthy PHP 8.1
 * shared host on HTTPS with every extension loaded and both var folders
 * writable; individual tests flip fields to exercise a warn or fail path.
 */
final class FakeProbe implements EnvironmentProbe
{
    public string $phpVersion = '8.1.27';

    /** @var array<string, bool> */
    public array $extensions = [
        'pdo_sqlite' => true,
        'pdo_mysql'  => true,
        'openssl'    => true,
        'curl'       => true,
    ];

    /** @var array<string, bool> Writability overrides keyed by absolute path. */
    public array $writable = [];

    public bool $writableDefault = true;

    public bool $https = true;

    public function phpVersion(): string
    {
        return $this->phpVersion;
    }

    public function hasExtension(string $name): bool
    {
        return $this->extensions[$name] ?? false;
    }

    public function isWritable(string $path): bool
    {
        return $this->writable[$path] ?? $this->writableDefault;
    }

    public function isHttps(): bool
    {
        return $this->https;
    }
}
