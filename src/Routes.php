<?php

declare(strict_types=1);

namespace OpenSendForm;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;

/**
 * Route definitions.
 *
 * Increment 0 exposes a single health-check route. Additional routes
 * (submission endpoint, admin UI, installer) arrive in later increments.
 */
final class Routes
{
    public static function register(App $app): void
    {
        $app->get('/health', [self::class, 'health']);
    }

    public static function health(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $payload = json_encode([
            'status'  => 'ok',
            'version' => Version::STRING,
        ], JSON_THROW_ON_ERROR);

        $response->getBody()->write($payload);

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);
    }
}
