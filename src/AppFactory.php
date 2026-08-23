<?php

declare(strict_types=1);

namespace OpenSendForm;

use DI\Container;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;

/**
 * Builds the Slim application.
 *
 * Kept free of any HTTP serving so tests can construct the app and drive
 * requests through it directly. public/index.php is the only place that
 * calls run().
 */
final class AppFactory
{
    /**
     * Create a fully-configured Slim application.
     *
     * @param Config|null $config Optional pre-built configuration; defaults
     *                            to values resolved from the environment.
     */
    public static function create(?Config $config = null): App
    {
        $config ??= Config::fromEnvironment();

        $container = new Container();
        $container->set(Config::class, $config);

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();

        $app->addRoutingMiddleware();

        $displayErrorDetails = $config->appEnv() !== 'production';
        $app->addErrorMiddleware($displayErrorDetails, true, true);

        Routes::register($app);

        return $app;
    }
}
