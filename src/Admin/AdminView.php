<?php

declare(strict_types=1);

namespace OpenSendForm\Admin;

use OpenSendForm\Auth\AuthService;
use OpenSendForm\Auth\Csrf;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Shared rendering for authenticated admin pages.
 *
 * Injects the chrome every signed-in screen needs — the top nav (with the
 * current admin's name and a CSRF-protected logout), any queued flash
 * messages (drained once), and the per-session CSRF token — then renders the
 * requested view through the plain-PHP TemplateRenderer. Pre-authentication
 * pages (login, TOTP entry) deliberately bypass this and render chrome-free.
 */
final class AdminView
{
    /**
     * Render an authenticated page with the standard chrome.
     *
     * @param array<string, mixed> $vars           View-specific variables.
     * @param array<int, string>   $extraScripts   Extra <script> srcs for this view.
     */
    public static function renderPage(
        ContainerInterface $c,
        ResponseInterface $response,
        string $view,
        array $vars,
        string $activeNav = '',
        int $status = 200,
        array $extraScripts = []
    ): ResponseInterface {
        /** @var AuthService $auth */
        $auth = $c->get(AuthService::class);
        /** @var Csrf $csrf */
        $csrf = $c->get(Csrf::class);
        /** @var Flash $flash */
        $flash = $c->get(Flash::class);

        $admin = $auth->currentAdmin();

        $common = [
            'showNav'      => true,
            'adminName'    => $admin['display_name'] ?? '',
            'activeNav'    => $activeNav,
            'csrf'         => $csrf->token(),
            'flashes'      => $flash->drain(),
            'extraScripts' => $extraScripts,
        ];

        /** @var TemplateRenderer $renderer */
        $renderer = $c->get(TemplateRenderer::class);
        $response->getBody()->write($renderer->render($view, $vars + $common));

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withStatus($status);
    }
}
