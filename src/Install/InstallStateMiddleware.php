<?php

declare(strict_types=1);

namespace OpenSendForm\Install;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Enforces the installed-state gate on every request.
 *
 * Two directions, per the installed-state model:
 *  - Not installed → the installer is the ONLY thing reachable; every other
 *    route (public API included) redirects to /install.
 *  - Installed → the installer is closed off; every /install route returns 404
 *    (the controller re-checks this too, so routing is not the only guard).
 *
 * The one exception is /install/done: it stays reachable so the success screen
 * survives the redirect after a finished install, and guards itself with a
 * one-time session flag in the controller.
 *
 * When the gate is DISABLED (no install paths supplied to the app factory —
 * the default used by the existing test suite and any embed-only wiring) the
 * app behaves as already installed and this middleware is a passthrough for
 * ordinary routes.
 */
final class InstallStateMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Paths $paths,
        private bool $enabled
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $isInstallPath = $path === '/install' || str_starts_with($path, '/install/');
        $isDone = $path === '/install/done';

        $installed = $this->enabled ? $this->paths->isInstalled() : true;

        if (!$installed) {
            // Only the installer runs until the app is installed.
            if ($isInstallPath) {
                return $handler->handle($request);
            }

            return (new Response())
                ->withHeader('Location', '/install')
                ->withStatus(302);
        }

        // Installed: the installer is gone (except the self-guarding done screen).
        if ($isInstallPath && !$isDone) {
            return (new Response())->withStatus(404);
        }

        return $handler->handle($request);
    }
}
