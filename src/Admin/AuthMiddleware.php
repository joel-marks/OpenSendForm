<?php

declare(strict_types=1);

namespace OpenSendForm\Admin;

use OpenSendForm\Auth\AuthService;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Guards protected admin routes.
 *
 * When there is no fully-authenticated admin (never logged in, or the idle /
 * absolute session timeout has elapsed — both enforced by AuthService), the
 * request is redirected to the login page instead of reaching the handler.
 * The login and TOTP-entry routes are deliberately NOT wrapped with this.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    private ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var AuthService $auth */
        $auth = $this->container->get(AuthService::class);

        if ($auth->currentAdmin() === null) {
            return (new Response())
                ->withHeader('Location', '/admin/login')
                ->withStatus(302);
        }

        return $handler->handle($request);
    }
}
