<?php

declare(strict_types=1);

namespace OpenSendForm;

use OpenSendForm\Form\FormRepository;
use OpenSendForm\Http\ApiResponse;
use OpenSendForm\Http\OriginMatcher;
use OpenSendForm\Security\SubmitToken;
use OpenSendForm\Submit\SubmitContext;
use OpenSendForm\Submit\SubmitPipeline;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Slim\App;

/**
 * Route definitions.
 *
 * Health check, plus the versioned public API (v1): a token endpoint the
 * embed JS calls before rendering a form, CORS preflight handlers, and the
 * submission endpoint driven by the ordered SubmitPipeline. Handlers pull
 * their collaborators from the app container captured at registration.
 */
final class Routes
{
    public static function register(App $app): void
    {
        $container = $app->getContainer();
        if ($container === null) {
            throw new RuntimeException('Routes require a DI container.');
        }

        $app->get('/health', [self::class, 'health']);

        // Non-static closures: Slim's CallableResolver binds route Closures to
        // the container ($this), which fails for static closures.
        $app->get(
            '/v1/form/{form_key}/token',
            function (ServerRequestInterface $req, ResponseInterface $res, array $args) use ($container): ResponseInterface {
                return Routes::token($container, $req, $res, $args);
            }
        );
        $app->options(
            '/v1/form/{form_key}/token',
            function (ServerRequestInterface $req, ResponseInterface $res, array $args) use ($container): ResponseInterface {
                return Routes::preflightToken($container, $req, $res, $args);
            }
        );

        // /v1/form/{form_key}/submit is mapped for the common methods so a
        // non-POST reaches our handler and returns the contract's
        // method_not_allowed body (rather than Slim's default 405). OPTIONS
        // is handled separately as a CORS preflight.
        $app->map(
            ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
            '/v1/form/{form_key}/submit',
            function (ServerRequestInterface $req, ResponseInterface $res, array $args) use ($container): ResponseInterface {
                return Routes::submit($container, $req, $res, $args);
            }
        );
        $app->options(
            '/v1/form/{form_key}/submit',
            function (ServerRequestInterface $req, ResponseInterface $res, array $args) use ($container): ResponseInterface {
                return Routes::preflightSubmit($container, $req, $res, $args);
            }
        );
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

    /**
     * GET /v1/form/{form_key}/token — issue a submit token for an allowed
     * origin. Unknown/inactive key: 403 unknown_form. Disallowed origin:
     * 403 origin_not_allowed.
     *
     * @param array<string, string> $args
     */
    private static function token(
        ContainerInterface $container,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        /** @var FormRepository $forms */
        $forms = $container->get(FormRepository::class);
        /** @var SubmitToken $tokens */
        $tokens = $container->get(SubmitToken::class);

        $form = $forms->findByKey($args['form_key'] ?? '');
        if ($form === null) {
            return ApiResponse::error($response, 403, 'unknown_form', 'Unknown or inactive form.');
        }

        $matched = OriginMatcher::match(
            self::header($request, 'Origin'),
            self::header($request, 'Referer'),
            $form['allowed_origins']
        );
        if ($matched === null) {
            return ApiResponse::error($response, 403, 'origin_not_allowed', 'Origin is not allowed for this form.');
        }

        $token = $tokens->issue($form['form_key']);

        return ApiResponse::withCors(
            ApiResponse::success($response, ['token' => $token]),
            $matched
        );
    }

    /**
     * OPTIONS /v1/form/{form_key}/token — CORS preflight for the token
     * endpoint (GET).
     *
     * @param array<string, string> $args
     */
    private static function preflightToken(
        ContainerInterface $container,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        /** @var FormRepository $forms */
        $forms = $container->get(FormRepository::class);

        $allowed = null;
        $form = $forms->findByKey($args['form_key'] ?? '');
        if ($form !== null) {
            $allowed = OriginMatcher::match(
                self::header($request, 'Origin'),
                self::header($request, 'Referer'),
                $form['allowed_origins']
            );
        }

        return self::preflight($response, 'GET', $allowed);
    }

    /**
     * OPTIONS /v1/form/{form_key}/submit — CORS preflight for the
     * submission endpoint (POST). The form key is in the URL, so the
     * preflight resolves the specific form and echoes the origin only if
     * that form's allowlist matches. Unknown/inactive key or a non-allowed
     * origin: no ACAO header.
     *
     * @param array<string, string> $args
     */
    private static function preflightSubmit(
        ContainerInterface $container,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        /** @var FormRepository $forms */
        $forms = $container->get(FormRepository::class);

        $allowed = null;
        $form = $forms->findByKey($args['form_key'] ?? '');
        if ($form !== null) {
            $allowed = OriginMatcher::match(
                self::header($request, 'Origin'),
                self::header($request, 'Referer'),
                $form['allowed_origins']
            );
        }

        return self::preflight($response, 'POST', $allowed);
    }

    /**
     * POST /v1/form/{form_key}/submit — run the submission pipeline and
     * render its outcome.
     *
     * @param array<string, string> $args
     */
    private static function submit(
        ContainerInterface $container,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        /** @var SubmitPipeline $pipeline */
        $pipeline = $container->get(SubmitPipeline::class);

        $context = new SubmitContext($request, $args['form_key'] ?? null);
        $outcome = $pipeline->run($context);

        $result = $outcome->isSuccess()
            ? ApiResponse::success($response)
            : ApiResponse::error($response, $outcome->status(), $outcome->code(), $outcome->message());

        // CORS headers ride on responses only once the origin has been
        // validated (matchedOrigin set by the origin stage).
        return ApiResponse::withCors($result, $context->matchedOrigin);
    }

    /**
     * Build a 204 CORS preflight response.
     */
    private static function preflight(
        ResponseInterface $response,
        string $methods,
        ?string $allowedOrigin
    ): ResponseInterface {
        $response = $response
            ->withStatus(204)
            ->withHeader('Access-Control-Allow-Methods', $methods)
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type')
            ->withHeader('Vary', 'Origin');

        if ($allowedOrigin !== null) {
            $response = $response->withHeader('Access-Control-Allow-Origin', $allowedOrigin);
        }

        return $response;
    }

    private static function header(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getHeaderLine($name);

        return $value === '' ? null : $value;
    }
}
