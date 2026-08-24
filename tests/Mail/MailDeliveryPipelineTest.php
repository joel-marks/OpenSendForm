<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Mail;

use OpenSendForm\AppFactory;
use OpenSendForm\Config;
use OpenSendForm\Form\FormRepository;
use OpenSendForm\Mail\MailerInterface;
use OpenSendForm\Security\SubmitToken;
use OpenSendForm\Storage\Database;
use OpenSendForm\Storage\MigrationRunner;
use OpenSendForm\Submit\SubmitContext;
use OpenSendForm\Tests\Support\FakeDnsChecker;
use OpenSendForm\Tests\Support\FakeMailer;
use OpenSendForm\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Integration coverage of the delivery stage wired into the submit pipeline,
 * driven through the app factory. The submitter's contract is the invariant:
 * the endpoint returns {"ok":true} whether the send succeeds, fails, or is
 * skipped — the difference shows only in the stored submission's status.
 */
final class MailDeliveryPipelineTest extends TestCase
{
    private const SECRET = 'mail-pipeline-secret';
    private const T0 = 1_700_000_000;
    private const ORIGIN = 'https://example.com';

    private Database $db;
    private FixedClock $clock;
    private FakeDnsChecker $dns;
    private FormRepository $forms;
    /** @var array<string,mixed> */
    private array $form;

    protected function setUp(): void
    {
        $this->db = Database::connect('sqlite::memory:');
        (new MigrationRunner($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();

        $this->clock = new FixedClock(self::T0);
        $this->dns = new FakeDnsChecker(true);
        $this->forms = new FormRepository($this->db);
        $this->form = $this->forms->createForm('Contact', 'owner@example.com', [self::ORIGIN]);
        $this->db->execute('UPDATE forms SET store_content = 1 WHERE id = :id', ['id' => $this->form['id']]);
    }

    public function testWorkingTransportReturnsOkAndMarksSent(): void
    {
        $mailer = new FakeMailer();

        $response = $this->submit($mailer);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true], $this->json($response));

        $row = $this->lastSubmission();
        self::assertSame('sent', $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertSame(1, $mailer->callCount());
        self::assertSame('owner@example.com', $mailer->lastCall()['to']);
        self::assertSame('ada@example.com', $mailer->lastCall()['replyTo']);
    }

    public function testFailingTransportStillReturnsOkButMarksFailed(): void
    {
        $mailer = new FakeMailer();
        $mailer->alwaysFail('connection refused');

        $response = $this->submit($mailer);

        // The submitter must never see the delivery failure.
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true], $this->json($response));

        $row = $this->lastSubmission();
        self::assertSame('failed', $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertSame('connection refused', $row['last_error']);
        self::assertNotNull($row['next_attempt_at']);
    }

    public function testUnconfiguredSmtpLeavesSubmissionReceived(): void
    {
        // A transport is available, but SMTP_HOST is empty: storage-only mode.
        // The delivery stage must skip entirely.
        $mailer = new FakeMailer();
        $config = Config::fromValues([
            'APP_ENV'    => 'testing',
            'APP_SECRET' => self::SECRET,
            'SMTP_HOST'  => '',
        ]);

        $response = $this->submit($mailer, $config);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true], $this->json($response));

        $row = $this->lastSubmission();
        self::assertSame('received', $row['status']);
        self::assertSame(0, (int) $row['attempts']);
        self::assertSame(0, $mailer->callCount());
    }

    public function testNoMailerWiredLeavesSubmissionReceived(): void
    {
        // The production storage-only path: no transport supplied at all.
        $response = $this->submit(null);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('received', $this->lastSubmission()['status']);
    }

    // --- Harness ----------------------------------------------------------

    private function submit(?MailerInterface $mailer, ?Config $config = null): ResponseInterface
    {
        $config ??= Config::fromEnvironment(['APP_ENV' => 'testing', 'APP_SECRET' => self::SECRET]);
        $app = $this->app($config, $mailer);

        $token = (new SubmitToken(self::SECRET, $this->clock, 3, 3600))->issue($this->form['form_key']);
        $this->clock->advance(3); // token old enough to be VALID

        return $app->handle($this->submitRequest([
            SubmitContext::FIELD_TOKEN => $token,
            'name'                     => 'Ada',
            'email'                    => 'ada@example.com',
            'message'                  => 'Hello there',
        ]));
    }

    private function app(Config $config, ?MailerInterface $mailer): App
    {
        return AppFactory::create($config, $this->db, $this->dns, $this->clock, $mailer);
    }

    /**
     * @param array<string,mixed> $fields
     */
    private function submitRequest(array $fields): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/v1/form/' . $this->form['form_key'] . '/submit', ['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Origin', self::ORIGIN);

        $request->getBody()->write(http_build_query($fields));
        $request->getBody()->rewind();

        return $request;
    }

    /**
     * @return array<string,mixed>
     */
    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string,mixed>
     */
    private function lastSubmission(): array
    {
        $row = $this->db->fetchOne('SELECT * FROM submissions ORDER BY id DESC LIMIT 1');
        self::assertNotNull($row);

        return $row;
    }
}
