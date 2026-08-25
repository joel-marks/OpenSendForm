<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Install;

use PHPUnit\Framework\TestCase;

/**
 * Locks the installer-polish field-test finding: on /install/database the
 * MySQL details are hidden while SQLite is selected and shown for MySQL — a
 * vanilla-JS enhancement that must degrade to the current always-visible layout
 * when JavaScript is off. This asserts the toggle HOOK exists (the template
 * markup and the JS that targets it); it is a contract check, not a DOM harness.
 */
final class InstallerDatabaseToggleTest extends TestCase
{
    private function read(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
    }

    public function testDatabaseTemplateCarriesTheToggleHooks(): void
    {
        $template = $this->read('templates/install/database.php');

        // The MySQL section is the toggle target...
        self::assertStringContainsString('data-mysql-details', $template);
        // ...and both driver radios are the toggle triggers.
        self::assertSame(2, substr_count($template, 'data-db-driver'));
    }

    public function testMysqlSectionIsVisibleByDefaultForNoJs(): void
    {
        $template = $this->read('templates/install/database.php');

        // The section must NOT be server-side hidden: with JS off it stays
        // visible (the always-visible layout the finding preserves).
        self::assertStringContainsString('<section data-mysql-details>', $template);
        self::assertStringNotContainsString('data-mysql-details hidden', $template);
    }

    public function testInstallJsTargetsTheHooksAndIsLoaded(): void
    {
        $js = $this->read('public/assets/install.js');
        self::assertStringContainsString('data-mysql-details', $js);
        self::assertStringContainsString('data-db-driver', $js);
        // Uses the `hidden` property to toggle, and reacts to radio changes.
        self::assertStringContainsString('.hidden', $js);
        self::assertStringContainsString("addEventListener('change'", $js);

        // And the installer layout actually loads it.
        $layout = $this->read('templates/install/layout.php');
        self::assertStringContainsString('/assets/install.js', $layout);
    }
}
