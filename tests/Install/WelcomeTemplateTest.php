<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Install;

use OpenSendForm\Admin\TemplateRenderer;
use OpenSendForm\Install\Requirements;
use PHPUnit\Framework\TestCase;

/**
 * Renders the installer welcome template directly to prove the requirements
 * table drives the Continue control: a failing check disables it (and shows
 * the remedy), while an all-clear set links onward.
 */
final class WelcomeTemplateTest extends TestCase
{
    private TemplateRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates/install');
    }

    public function testFailingRequirementDisablesContinueAndShowsRemedy(): void
    {
        $html = $this->renderer->render('welcome', [
            'title'       => 'Install',
            'hasFailures' => true,
            'flashes'     => [],
            'checks'      => [[
                'key'    => 'openssl',
                'label'  => 'Encryption support (openssl)',
                'status' => Requirements::FAIL,
                'remedy' => 'Enable the openssl extension in cPanel.',
            ]],
        ]);

        self::assertStringContainsString('osf-disabled-link', $html);
        self::assertStringContainsString('Enable the openssl extension in cPanel.', $html);
        self::assertStringNotContainsString('href="/install/database"', $html);
        self::assertStringContainsString('Action needed', $html);
    }

    public function testAllClearRequirementsLinkOnward(): void
    {
        $html = $this->renderer->render('welcome', [
            'title'       => 'Install',
            'hasFailures' => false,
            'flashes'     => [],
            'checks'      => [[
                'key'    => 'php_version',
                'label'  => 'PHP 8.1.27',
                'status' => Requirements::PASS,
                'remedy' => '',
            ]],
        ]);

        self::assertStringContainsString('href="/install/database"', $html);
        self::assertStringNotContainsString('osf-disabled-link', $html);
    }
}
