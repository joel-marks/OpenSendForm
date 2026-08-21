<?php

declare(strict_types=1);

namespace OpenSendForm\Tests;

use OpenSendForm\AppFactory;
use OpenSendForm\Config;
use OpenSendForm\Version;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class HealthTest extends TestCase
{
    public function testHealthReturnsOkJson(): void
    {
        $app = AppFactory::create(Config::fromEnvironment(['APP_ENV' => 'testing']));

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/health');
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(
            'application/json',
            $response->getHeaderLine('Content-Type')
        );

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('ok', $decoded['status']);
        self::assertSame(Version::STRING, $decoded['version']);
    }
}
