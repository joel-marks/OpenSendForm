<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Http;

use OpenSendForm\AppFactory;
use OpenSendForm\Config;
use OpenSendForm\Storage\Database;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * GET / has no page of its own; on an installed instance it bounces to the
 * sign-in screen instead of falling through to Slim's raw 404 (the bug this
 * closes — an installed site's root URL used to 404).
 */
final class RootRedirectTest extends TestCase
{
    public function testInstalledRootRedirectsToAdminLogin(): void
    {
        $config = Config::fromValues(['APP_ENV' => 'testing', 'APP_SECRET' => 'root-redirect-secret']);
        // No install paths supplied: the gate is disabled, i.e. the app
        // behaves as already installed (the same default every other HTTP
        // test in this suite relies on).
        $app = AppFactory::create($config, Database::connect('sqlite::memory:'));

        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/')
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/login', $response->getHeaderLine('Location'));
    }
}
