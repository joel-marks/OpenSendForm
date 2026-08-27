<?php

declare(strict_types=1);

namespace OpenSendForm\Tests;

use OpenSendForm\Version;
use PHPUnit\Framework\TestCase;

/**
 * The version string is the single source of truth the release build reads to
 * name the zip and stamp the install lock, so its shape must stay predictable.
 */
final class VersionTest extends TestCase
{
    public function testVersionIsSemver(): void
    {
        self::assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            Version::STRING,
            'Version::STRING must be a bare MAJOR.MINOR.PATCH semver.'
        );
    }

    public function testFirstPackagedVersion(): void
    {
        // 0.1.0 is the first packaged release (Increment 8). This guards against
        // an accidental revert to the pre-packaging 0.0.1.
        self::assertSame('0.1.0', Version::STRING);
    }
}
