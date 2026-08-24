<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Submit;

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
use OpenSendForm\Tests\Support\FakeTurnstileVerifier;
use OpenSendForm\Tests\Support\FixedClock;
use OpenSendForm\Turnstile\TurnstileResult;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * End-to-end coverage of the optional per-form Turnstile stage, driven
 * through the app factory with a scriptable fake verifier (never the real
 * Cloudflare API). Proves the enable gate, the two honest rejections, and
 * the fail-open policy — and that a disabled form makes zero verify calls.
 */
final class TurnstilePipelineTest extends TestCase
{
    private const SECRET = 'turnstile-pipeline-secret';
    private const T0 = 1_700_000_000;
    private const ORIGIN = 'https://example.com';
    private const SITEKEY = '0xSITEKEY';
    private const CF_SECRET = '0xSECRETKEY';

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

    public function testEnabledAndValidProceedsToStoreAndSend(): void
    {
        $this->enableTurnstile();
        $verifier = new FakeTurnstileVerifier(TurnstileResult::VALID);
        $mailer = new FakeMailer();

        $response = $this->submit($verifier, $mailer, [SubmitContext::FIELD_TURNSTILE => 'cf-token']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true], $this->json($response));
        self::assertSame(1, $verifier->callCount());
        // The secret and submitter IP are handed to the verifier, not the sitekey.
        self::assertSame(self::CF_SECRET, $verifier->calls[0]['secret']);
        self::assertSame('cf-token', $verifier->calls[0]['token']);
        self::assertSame('203.0.113.7', $verifier->calls[0]['remoteIp']);

        $row = $this->lastSubmission();
        self::assertSame('sent', $row['status']);
        self::assertSame(1, $mailer->callCount());
    }

    public function testEnabledAndInvalidRejectsWithTurnstileFailedAndStoresNothing(): void
    {
        $this->enableTurnstile();
        $verifier = new FakeTurnstileVerifier(TurnstileResult::INVALID);
        $mailer = new FakeMailer();

        $response = $this->submit($verifier, $mailer, [SubmitContext::FIELD_TURNSTILE => 'cf-token']);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('turnstile_failed', $this->json($response)['error']['code']);
        self::assertSame(0, $this->submissionCount());
        self::assertSame(0, $mailer->callCount());
    }

    public function testEnabledButMissingTokenRejectsWithTurnstileRequiredAndStoresNothing(): void
    {
        $this->enableTurnstile();
        $verifier = new FakeTurnstileVerifier(TurnstileResult::VALID);

        // No _osf_cf field on the request.
        $response = $this->submit($verifier, new FakeMailer(), []);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('turnstile_required', $this->json($response)['error']['code']);
        self::assertSame(0, $this->submissionCount());
        // The API is never called when the token is absent.
        self::assertSame(0, $verifier->callCount());
    }

    public function testEnabledAndUnavailableFailsOpenAndProceeds(): void
    {
        $this->enableTurnstile();
        $verifier = new FakeTurnstileVerifier(TurnstileResult::UNAVAILABLE);
        $mailer = new FakeMailer();

        $response = $this->submit($verifier, $mailer, [SubmitContext::FIELD_TURNSTILE => 'cf-token']);

        // Fail open: verify was unreachable, but the submission still lands.
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true], $this->json($response));
        self::assertSame(1, $verifier->callCount());
        self::assertSame(1, $this->submissionCount());
        self::assertSame('sent', $this->lastSubmission()['status']);
    }

    public function testDisabledFormSkipsVerificationEntirely(): void
    {
        // Turnstile not enabled on this form.
        $verifier = new FakeTurnstileVerifier(TurnstileResult::INVALID);
        $mailer = new FakeMailer();

        // Even with a stray _osf_cf field present, no verify call is made.
        $response = $this->submit($verifier, $mailer, [SubmitContext::FIELD_TURNSTILE => 'ignored']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true], $this->json($response));
        self::assertSame(0, $verifier->callCount());
        self::assertSame(1, $this->submissionCount());
    }

    // --- Token endpoint ---------------------------------------------------

    public function testTokenEndpointIncludesSitekeyWhenEnabled(): void
    {
        $this->enableTurnstile();

        $json = $this->json($this->tokenRequest());

        self::assertTrue($json['ok']);
        self::assertArrayHasKey('turnstile', $json);
        self::assertSame(self::SITEKEY, $json['turnstile']['sitekey']);
        // The secret must never appear anywhere in the response.
        self::assertStringNotContainsString(self::CF_SECRET, json_encode($json, JSON_THROW_ON_ERROR));
    }

    public function testTokenEndpointOmitsTurnstileWhenDisabled(): void
    {
        $json = $this->json($this->tokenRequest());

        self::assertTrue($json['ok']);
        self::assertArrayNotHasKey('turnstile', $json);
    }

    // --- Harness ----------------------------------------------------------

    private function enableTurnstile(): void
    {
        $this->forms->setTurnstile($this->form['id'], self::SITEKEY, self::CF_SECRET);
    }

    /**
     * @param array<string,mixed> $extraFields
     */
    private function submit(
        FakeTurnstileVerifier $verifier,
        ?MailerInterface $mailer,
        array $extraFields
    ): ResponseInterface {
        $config = Config::fromEnvironment(['APP_ENV' => 'testing', 'APP_SECRET' => self::SECRET]);
        $app = AppFactory::create($config, $this->db, $this->dns, $this->clock, $mailer, $verifier);

        $token = (new SubmitToken(self::SECRET, $this->clock, 3, 3600))->issue($this->form['form_key']);
        $this->clock->advance(3); // token old enough to be VALID

        $fields = array_merge([
            SubmitContext::FIELD_TOKEN => $token,
            'name'                     => 'Ada',
            'email'                    => 'ada@example.com',
            'message'                  => 'Hello there',
        ], $extraFields);

        return $app->handle($this->submitRequest($fields));
    }

    private function tokenRequest(): ResponseInterface
    {
        $config = Config::fromEnvironment(['APP_ENV' => 'testing', 'APP_SECRET' => self::SECRET]);
        $app = AppFactory::create($config, $this->db, $this->dns, $this->clock);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/v1/form/' . $this->form['form_key'] . '/token')
            ->withHeader('Origin', self::ORIGIN);

        return $app->handle($request);
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

    private function submissionCount(): int
    {
        $row = $this->db->fetchOne('SELECT COUNT(*) AS c FROM submissions');

        return (int) $row['c'];
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
