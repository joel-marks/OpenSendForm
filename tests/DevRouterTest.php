<?php

declare(strict_types=1);

namespace OpenSendForm\Tests;

use PHPUnit\Framework\TestCase;

/**
 * `composer run serve` boots PHP's built-in server, which (unlike Apache's
 * production rewrite) never forwards a request whose path resembles a static
 * file to the front controller, and dumps existing files straight to disk
 * without going through a router at all unless one is given. public/
 * dev-router.php is that seam: it exists only for the built-in server and is
 * never the production entry point (public/index.php still is).
 */
final class DevRouterTest extends TestCase
{
    public function testDevRouterFileExists(): void
    {
        self::assertFileExists(dirname(__DIR__) . '/public/dev-router.php');
    }

    public function testDevRouterIsMarkedDevOnlyAndNeverProductionEntry(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/public/dev-router.php');

        self::assertStringContainsString('built-in server', $source);
        self::assertStringContainsString('Never the', $source);
        self::assertStringContainsString('production entry point', $source);
    }

    public function testDevRouterFallsThroughToIndexForNonFilePaths(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/public/dev-router.php');

        self::assertStringContainsString("require __DIR__ . '/index.php';", $source);
        self::assertStringContainsString('is_file($file)', $source);
        self::assertStringContainsString('return false;', $source);
    }

    public function testServeScriptReferencesTheDevRouter(): void
    {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertArrayHasKey('serve', $composer['scripts']);
        self::assertStringContainsString('public/dev-router.php', $composer['scripts']['serve']);
    }

    public function testProcessTimeoutIsDisabled(): void
    {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(0, $composer['config']['process-timeout']);
    }
}
