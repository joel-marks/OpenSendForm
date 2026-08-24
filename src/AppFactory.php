<?php

declare(strict_types=1);

namespace OpenSendForm;

use DI\Container;
use OpenSendForm\Clock\Clock;
use OpenSendForm\Clock\SystemClock;
use OpenSendForm\Form\FormRepository;
use OpenSendForm\RateLimit\RateLimiter;
use OpenSendForm\Security\SubmitToken;
use OpenSendForm\Storage\Database;
use OpenSendForm\Submission\SubmissionRepository;
use OpenSendForm\Submit\SubmitPipeline;
use OpenSendForm\Validation\DnsChecker;
use OpenSendForm\Validation\SystemDnsChecker;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;

/**
 * Builds the Slim application.
 *
 * Kept free of any HTTP serving so tests can construct the app and drive
 * requests through it directly. public/index.php is the only place that
 * calls run(). Collaborators (database, clock, DNS checker) are injectable
 * so tests can supply an in-memory database, a fixed clock and a fake
 * resolver.
 */
final class AppFactory
{
    /**
     * Create a fully-configured Slim application.
     *
     * @param Config|null     $config Optional pre-built configuration.
     * @param Database|null   $db     Optional database; defaults to a
     *                                connection from the configured DSN.
     * @param DnsChecker|null $dns    Optional DNS checker; defaults to the
     *                                real system resolver.
     * @param Clock|null      $clock  Optional clock; defaults to the system
     *                                clock.
     */
    public static function create(
        ?Config $config = null,
        ?Database $db = null,
        ?DnsChecker $dns = null,
        ?Clock $clock = null
    ): App {
        $config ??= Config::fromEnvironment();
        $db ??= Database::connect($config->dbDsn());
        $dns ??= new SystemDnsChecker();
        $clock ??= new SystemClock();

        $container = self::buildContainer($config, $db, $dns, $clock);

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();

        $app->addRoutingMiddleware();

        $displayErrorDetails = $config->appEnv() !== 'production';
        $app->addErrorMiddleware($displayErrorDetails, true, true);

        Routes::register($app);

        return $app;
    }

    private static function buildContainer(
        Config $config,
        Database $db,
        DnsChecker $dns,
        Clock $clock
    ): Container {
        $container = new Container();

        $container->set(Config::class, $config);
        $container->set(Database::class, $db);
        $container->set(DnsChecker::class, $dns);
        $container->set(Clock::class, $clock);

        $forms = new FormRepository($db);
        $submissions = new SubmissionRepository($db);
        $limiter = new RateLimiter($db, $clock);
        $tokens = new SubmitToken(
            $config->appSecret(),
            $clock,
            $config->minSubmitSeconds(),
            $config->tokenMaxAgeSeconds()
        );

        $container->set(FormRepository::class, $forms);
        $container->set(SubmissionRepository::class, $submissions);
        $container->set(RateLimiter::class, $limiter);
        $container->set(SubmitToken::class, $tokens);
        $container->set(
            SubmitPipeline::class,
            SubmitPipeline::create($config, $forms, $limiter, $tokens, $dns, $submissions)
        );

        return $container;
    }
}
