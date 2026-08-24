<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Mail;

use OpenSendForm\Config;
use OpenSendForm\Form\FormRepository;
use OpenSendForm\Mail\DeliveryService;
use OpenSendForm\Mail\MessageBuilder;
use OpenSendForm\Storage\Database;
use OpenSendForm\Storage\MigrationRunner;
use OpenSendForm\Submission\SubmissionRepository;
use OpenSendForm\Tests\Support\FakeMailer;
use OpenSendForm\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural coverage of the delivery service: the success path, the
 * failure/backoff scheduling, escalation to 'dead' at the attempt ceiling,
 * and the retry sweep selecting only due items. A fixed clock makes every
 * scheduled timestamp exact; the fake mailer means no real SMTP is touched.
 */
final class DeliveryServiceTest extends TestCase
{
    private const T0 = 1_700_000_000;

    private Database $db;
    private FixedClock $clock;
    private FakeMailer $mailer;
    private FormRepository $forms;
    private SubmissionRepository $submissions;
    /** @var array<string,mixed> */
    private array $form;

    protected function setUp(): void
    {
        $this->db = Database::connect('sqlite::memory:');
        (new MigrationRunner($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();

        $this->clock = new FixedClock(self::T0);
        $this->mailer = new FakeMailer();
        $this->forms = new FormRepository($this->db);
        $this->submissions = new SubmissionRepository($this->db);

        $this->form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        // Store content so the built body carries the submitted fields.
        $this->db->execute('UPDATE forms SET store_content = 1 WHERE id = :id', ['id' => $this->form['id']]);
    }

    public function testSuccessMarksSentAndSendsExpectedMessage(): void
    {
        $id = $this->storeSubmission(['name' => 'Ada', 'email' => 'ada@example.com', 'message' => 'Hi']);

        $result = $this->service()->attemptDelivery($id);

        self::assertSame(DeliveryService::RESULT_SENT, $result);

        $row = $this->submissions->findById($id);
        self::assertSame('sent', $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertSame(gmdate('Y-m-d H:i:s', self::T0), $row['last_attempt_at']);
        self::assertNull($row['next_attempt_at']);
        self::assertNull($row['last_error']);

        self::assertSame(1, $this->mailer->callCount());
        $call = $this->mailer->lastCall();
        self::assertSame('owner@example.com', $call['to']);
        self::assertSame('ada@example.com', $call['replyTo']);
        self::assertStringContainsString('Contact', $call['subject']);
        self::assertStringContainsString('message: Hi', $call['textBody']);
    }

    public function testFailureMarksFailedAndSchedulesFirstBackoff(): void
    {
        $this->mailer->alwaysFail('smtp down');
        $id = $this->storeSubmission(['name' => 'Ada']);

        $result = $this->service()->attemptDelivery($id);

        self::assertSame(DeliveryService::RESULT_FAILED, $result);

        $row = $this->submissions->findById($id);
        self::assertSame('failed', $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertSame('smtp down', $row['last_error']);
        self::assertSame(gmdate('Y-m-d H:i:s', self::T0), $row['last_attempt_at']);
        // Backoff list '1,5,30': first failure waits 1 minute.
        self::assertSame(gmdate('Y-m-d H:i:s', self::T0 + 60), $row['next_attempt_at']);
    }

    public function testBackoffAdvancesThenEscalatesToDeadAtMaxAttempts(): void
    {
        $this->mailer->alwaysFail();
        $service = $this->service();
        $id = $this->storeSubmission(['name' => 'Ada']);

        // Attempt 1 -> failed, wait 1 minute.
        self::assertSame(DeliveryService::RESULT_FAILED, $service->attemptDelivery($id));
        self::assertSame(gmdate('Y-m-d H:i:s', self::T0 + 60), $this->submissions->findById($id)['next_attempt_at']);

        // Attempt 2 -> failed, wait 5 minutes (second backoff value).
        self::assertSame(DeliveryService::RESULT_FAILED, $service->attemptDelivery($id));
        $row = $this->submissions->findById($id);
        self::assertSame(2, (int) $row['attempts']);
        self::assertSame(gmdate('Y-m-d H:i:s', self::T0 + 300), $row['next_attempt_at']);

        // Attempt 3 reaches MAIL_MAX_ATTEMPTS (3) -> dead, no next attempt.
        self::assertSame(DeliveryService::RESULT_DEAD, $service->attemptDelivery($id));
        $row = $this->submissions->findById($id);
        self::assertSame('dead', $row['status']);
        self::assertSame(3, (int) $row['attempts']);
        self::assertNull($row['next_attempt_at']);
    }

    public function testBackoffReusesLastValueBeyondListLength(): void
    {
        $this->mailer->alwaysFail();
        // Long ceiling, short list: the last backoff value (5) must repeat.
        $config = Config::fromEnvironment([
            'MAIL_MAX_ATTEMPTS'          => '6',
            'MAIL_RETRY_BACKOFF_MINUTES' => '1,5',
        ]);
        $service = $this->service($config);
        $id = $this->storeSubmission(['name' => 'Ada']);

        $service->attemptDelivery($id); // attempt 1 -> +1 min
        $service->attemptDelivery($id); // attempt 2 -> +5 min
        $service->attemptDelivery($id); // attempt 3 -> +5 min (reused)
        $row = $this->submissions->findById($id);

        self::assertSame(3, (int) $row['attempts']);
        self::assertSame('failed', $row['status']);
        self::assertSame(gmdate('Y-m-d H:i:s', self::T0 + 300), $row['next_attempt_at']);
    }

    public function testRetryDueReattemptsOnlyDueItems(): void
    {
        $service = $this->service();

        $due = $this->storeSubmission(['name' => 'Due']);
        $notYet = $this->storeSubmission(['name' => 'Later']);

        // One is due in the past, one scheduled far in the future.
        $this->submissions->markFailed($due, 1, 'err', gmdate('Y-m-d H:i:s', self::T0), '2000-01-01 00:00:00');
        $this->submissions->markFailed($notYet, 1, 'err', gmdate('Y-m-d H:i:s', self::T0), '2999-01-01 00:00:00');

        $summary = $service->retryDue('2025-01-01 00:00:00');

        self::assertSame(1, $summary['attempted']);
        self::assertSame(1, $summary[DeliveryService::RESULT_SENT]);
        self::assertSame('sent', $this->submissions->findById($due)['status']);
        self::assertSame('failed', $this->submissions->findById($notYet)['status']);
        self::assertSame(1, $this->mailer->callCount());
    }

    public function testRetryDueUsesClockWhenNoCutoffGiven(): void
    {
        $service = $this->service();
        $id = $this->storeSubmission(['name' => 'Ada']);
        $this->submissions->markFailed($id, 1, 'err', gmdate('Y-m-d H:i:s', self::T0), gmdate('Y-m-d H:i:s', self::T0 + 60));

        // Before the scheduled time: nothing due.
        $before = $service->retryDue();
        self::assertSame(0, $before['attempted']);

        // Advance past the schedule: now due.
        $this->clock->advance(120);
        $after = $service->retryDue();
        self::assertSame(1, $after['attempted']);
        self::assertSame(1, $after[DeliveryService::RESULT_SENT]);
    }

    public function testMissingSubmissionIsSkipped(): void
    {
        self::assertSame(DeliveryService::RESULT_SKIPPED, $this->service()->attemptDelivery(999));
        self::assertSame(0, $this->mailer->callCount());
    }

    // --- Helpers ----------------------------------------------------------

    private function service(?Config $config = null): DeliveryService
    {
        $config ??= Config::fromEnvironment([
            'MAIL_MAX_ATTEMPTS'          => '3',
            'MAIL_RETRY_BACKOFF_MINUTES' => '1,5,30',
        ]);

        return new DeliveryService(
            $this->submissions,
            $this->forms,
            new MessageBuilder(),
            $this->mailer,
            $config,
            $this->clock
        );
    }

    /**
     * @param array<string,string> $fields
     */
    private function storeSubmission(array $fields): int
    {
        return $this->submissions->recordSubmission(
            (int) $this->form['id'],
            '203.0.113.7',
            'https://example.com',
            'PHPUnit',
            (string) json_encode($fields),
            'received'
        );
    }
}
