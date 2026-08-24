<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Submit;

use OpenSendForm\Config;
use OpenSendForm\Form\FormRepository;
use OpenSendForm\RateLimit\RateLimiter;
use OpenSendForm\Security\SubmitToken;
use OpenSendForm\Storage\Database;
use OpenSendForm\Storage\MigrationRunner;
use OpenSendForm\Submission\SubmissionRepository;
use OpenSendForm\Submit\Stages\EmailValidationStage;
use OpenSendForm\Submit\Stages\FieldHygieneStage;
use OpenSendForm\Submit\Stages\FormLookupStage;
use OpenSendForm\Submit\Stages\HoneypotStage;
use OpenSendForm\Submit\Stages\MethodBodyStage;
use OpenSendForm\Submit\Stages\OriginStage;
use OpenSendForm\Submit\Stages\RateLimitStage;
use OpenSendForm\Submit\Stages\StoreStage;
use OpenSendForm\Submit\Stages\TokenStage;
use OpenSendForm\Submit\SubmitPipeline;
use OpenSendForm\Tests\Support\FakeDnsChecker;
use OpenSendForm\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;

/**
 * Locks the exact stage order of the submission pipeline. The order is the
 * endpoint's security contract (cheapest/most-abuse-relevant first, storage
 * last); an accidental reordering must fail this test.
 */
final class SubmitPipelineOrderTest extends TestCase
{
    public function testStagesRunInSpecifiedOrder(): void
    {
        $db = Database::connect('sqlite::memory:');
        (new MigrationRunner($db, dirname(__DIR__, 2) . '/migrations'))->migrate();

        $config = Config::fromEnvironment(['APP_ENV' => 'testing', 'APP_SECRET' => 's']);
        $clock = new FixedClock();

        $pipeline = SubmitPipeline::create(
            $config,
            new FormRepository($db),
            new RateLimiter($db, $clock),
            new SubmitToken('s', $clock, 3, 3600),
            new FakeDnsChecker(),
            new SubmissionRepository($db)
        );

        $classes = array_map(
            static fn (object $stage): string => $stage::class,
            $pipeline->stages()
        );

        self::assertSame([
            MethodBodyStage::class,
            FieldHygieneStage::class,
            FormLookupStage::class,
            OriginStage::class,
            RateLimitStage::class,
            HoneypotStage::class,
            TokenStage::class,
            EmailValidationStage::class,
            StoreStage::class,
        ], $classes);
    }
}
