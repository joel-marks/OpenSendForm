<?php

declare(strict_types=1);

namespace OpenSendForm\Admin;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/**
 * Server-rendered admin routes under /admin.
 *
 * The whole group carries the hardening headers. The login and TOTP-entry
 * routes are public-facing (pre-authentication); the dashboard, TOTP
 * enrolment and logout routes are wrapped with AuthMiddleware so an
 * unauthenticated request is redirected to the login page.
 */
final class AdminRoutes
{
    public static function register(App $app): void
    {
        $container = $app->getContainer();
        if ($container === null) {
            throw new RuntimeException('Admin routes require a DI container.');
        }

        $auth = new AuthMiddleware($container);

        $app->group('/admin', function (RouteCollectorProxy $group) use ($container, $auth): void {
            // Pre-authentication routes.
            $group->get('/login', self::handler($container, [AdminController::class, 'loginForm']));
            $group->post('/login', self::handler($container, [AdminController::class, 'login']));
            $group->get('/totp', self::handler($container, [AdminController::class, 'totpForm']));
            $group->post('/totp', self::handler($container, [AdminController::class, 'totp']));

            // Authenticated routes.
            $group->post('/logout', self::handler($container, [AdminController::class, 'logout']))
                ->add($auth);
            $group->get('/totp/setup', self::handler($container, [AdminController::class, 'totpSetupForm']))
                ->add($auth);
            $group->post('/totp/setup', self::handler($container, [AdminController::class, 'totpSetup']))
                ->add($auth);
            $group->post(
                '/totp/recovery-codes/regenerate',
                self::handler($container, [AdminController::class, 'regenerateRecoveryCodes'])
            )->add($auth);

            // Dashboard is the group root: /admin.
            $group->get('', self::handler($container, [AdminController::class, 'dashboard']))
                ->add($auth);
        })->add(new SecurityHeadersMiddleware());
    }

    /**
     * Wrap a controller method in a Closure bound over the container.
     *
     * Slim binds route Closures to the container ($this), so these must be
     * non-static closures — matching the public Routes class.
     *
     * @param callable(ContainerInterface, ServerRequestInterface, ResponseInterface): ResponseInterface $target
     */
    private static function handler(ContainerInterface $container, callable $target): \Closure
    {
        return function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($container, $target): ResponseInterface {
            return $target($container, $request, $response);
        };
    }
}
