<?php

declare(strict_types=1);

namespace OpenSendForm;

use DI\Container;
use OpenSendForm\Clock\Clock;
use OpenSendForm\Clock\SystemClock;
use OpenSendForm\Form\FormRepository;
use OpenSendForm\Mail\DeliveryService;
use OpenSendForm\Mail\MailerInterface;
use OpenSendForm\Mail\MessageBuilder;
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
     * @param MailerInterface|null $mailer Optional SMTP transport. When null,
     *                                mail delivery is disabled and submissions
     *                                stop at 'received' (storage-only); the
     *                                front controller supplies a real mailer
     *                                only when SMTP is configured.
     */
    public static function create(
        ?Config $config = null,
        ?Database $db = null,
        ?DnsChecker $dns = null,
        ?Clock $clock = null,
        ?MailerInterface $mailer = null
    ): App {
        $config ??= Config::fromEnvironment();
        $db ??= Database::connect($config->dbDsn());
        $dns ??= new SystemDnsChecker();
        $clock ??= new SystemClock();

        $container = self::buildContainer($config, $db, $dns, $clock, $mailer);

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
        Clock $clock,
        ?MailerInterface $mailer
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

        // Mail delivery is wired only when a transport is supplied; otherwise
        // the delivery stage runs as a no-op and submissions stay 'received'.
        $delivery = $mailer === null ? null : new DeliveryService(
            $submissions,
            $forms,
            new MessageBuilder(),
            $mailer,
            $config,
            $clock
        );

        $container->set(FormRepository::class, $forms);
        $container->set(SubmissionRepository::class, $submissions);
        $container->set(RateLimiter::class, $limiter);
        $container->set(SubmitToken::class, $tokens);
        if ($delivery !== null) {
            $container->set(DeliveryService::class, $delivery);
        }
        $container->set(
            SubmitPipeline::class,
            SubmitPipeline::create($config, $forms, $limiter, $tokens, $dns, $submissions, $delivery)
        );

        return $container;
    }
}
