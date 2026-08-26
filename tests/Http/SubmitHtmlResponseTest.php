<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Http;

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
 * Content negotiation on the submission endpoint (Increment 7).
 *
 * A native (no-JS) browser form POST is a top-level navigation that prefers
 * text/html; it must receive a readable HTML page rather than the JSON contract.
 * Every fetch/API caller keeps JSON. These tests pin both the HTML fallback and
 * that the frozen JSON contract is untouched for the existing callers.
 */
final class SubmitHtmlResponseTest extends TestCase
{
    private const SECRET = 'html-response-secret';
    private const T0 = 1_700_000_000;
    private const ORIGIN = 'https://example.com';

    private Database $db;
    private FixedClock $clock;
    private FakeDnsChecker $dns;
    private FormRepository $forms;
    /** @var array<string, mixed> */
    private array $form;

    protected function setUp(): void
    {
        $this->db = Database::connect('sqlite::memory:');
        (new MigrationRunner($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();

        $this->clock = new FixedClock(self::T0);
        $this->dns = new FakeDnsChecker(true);
        $this->forms = new FormRepository($this->db);
        $this->form = $this->forms->createForm('Contact', 'owner@example.com', [self::ORIGIN]);
    }

    // --- HTML fallback ----------------------------------------------------

    public function testNativeFormSuccessRendersHtmlPageAndStores(): void
    {
        $token = $this->freshToken();
        $this->clock->advance(3);

        $response = $this->handle($this->submitRequest([
            SubmitContext::FIELD_TOKEN => $token,
            'name'                     => 'Ada',
        ], ['accept' => 'text/html,application/xhtml+xml', 'referer' => self::ORIGIN . '/contact']));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        $body = (string) $response->getBody();
        self::assertStringContainsString('<!DOCTYPE html>', $body);
        self::assertStringContainsString('Message sent', $body);
        // Real success, so the submission is stored (no silent discard here).
        self::assertSame(1, $this->submissionCount());
    }

    public function testNativeFormErrorRendersHtmlPageWithMessageAndBackLink(): void
    {
        $response = $this->handle($this->submitRequest([
            'name' => 'Ada',
        ], [
            'accept'   => 'text/html',
            'referer'  => self::ORIGIN . '/contact',
            'form_key' => 'osf_unknownkey',
        ]));

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        $body = (string) $response->getBody();
        self::assertStringContainsString('Something went wrong', $body);
        self::assertStringContainsString('Unknown or inactive form.', $body);
        // A valid http(s) Referer becomes the "back to the form" link.
        self::assertStringContainsString('href="' . self::ORIGIN . '/contact"', $body);
    }

    public function testHtmlBackLinkRejectsNonHttpReferer(): void
    {
        $response = $this->handle($this->submitRequest([
            'name' => 'Ada',
        ], [
            'accept'   => 'text/html',
            'referer'  => 'javascript:alert(1)',
            'form_key' => 'osf_unknownkey',
        ]));

        $body = (string) $response->getBody();
        self::assertStringNotContainsString('javascript:', $body);
        self::assertStringNotContainsString('Back to the form', $body);
    }

    public function testHtmlErrorPageIsWellFormed(): void
    {
        $response = $this->handle($this->submitRequest([
            'name' => 'Ada',
        ], ['accept' => 'text/html', 'form_key' => 'osf_unknownkey']));

        $body = (string) $response->getBody();
        self::assertStringContainsString('<h1>Something went wrong</h1>', $body);
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $body);
    }

    // --- No-JS token policy (allow_nojs) -----------------------------------

    public function testDefaultFormTokenlessHtmlPostGetsHonestErrorAndStoresNothing(): void
    {
        // allow_nojs defaults off. A no-JS post carries no _osf_token at all
        // (only osf.js fetches and injects one).
        $response = $this->handle($this->submitRequest([
            'name' => 'Ada',
        ], ['accept' => 'text/html', 'referer' => self::ORIGIN . '/contact']));

        self::assertSame(400, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('requires JavaScript', $body);
        self::assertStringContainsString('was not sent', $body);
        self::assertStringNotContainsString('Message sent', $body);
        self::assertSame(0, $this->submissionCount());
    }

    public function testDefaultFormInvalidTokenHtmlPostGetsHonestErrorAndStoresNothing(): void
    {
        $response = $this->handle($this->submitRequest([
            SubmitContext::FIELD_TOKEN => 'not-a-real-token',
            'name'                     => 'Ada',
        ], ['accept' => 'text/html', 'referer' => self::ORIGIN . '/contact']));

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('requires JavaScript', (string) $response->getBody());
        self::assertSame(0, $this->submissionCount());
    }

    public function testAllowNojsFormTokenlessHtmlPostIsStoredAndDelivered(): void
    {
        $this->forms->updateForm(
            (int) $this->form['id'],
            'Contact',
            'owner@example.com',
            [self::ORIGIN],
            false,
            30,
            true,
            true // allow_nojs
        );
        $mailer = new FakeMailer();

        $response = $this->handle($this->submitRequest([
            'name' => 'Ada',
        ], ['accept' => 'text/html', 'referer' => self::ORIGIN . '/contact']), $mailer);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Message sent', (string) $response->getBody());
        self::assertSame(1, $this->submissionCount());
        self::assertSame(1, $mailer->callCount());
    }

    public function testAllowNojsFormHoneypotFilledHtmlPostGetsGenericSuccessButDiscards(): void
    {
        // A filled honeypot is never a genuine no-JS submitter (humans never
        // fill it), so it still gets the generic success page — never the
        // honest javascript_required error — and is still silently discarded.
        $this->forms->updateForm(
            (int) $this->form['id'],
            'Contact',
            'owner@example.com',
            [self::ORIGIN],
            false,
            30,
            true,
            true // allow_nojs
        );
        $mailer = new FakeMailer();

        $response = $this->handle($this->submitRequest([
            'name'                        => 'Ada',
            SubmitContext::FIELD_HONEYPOT => 'i am a bot',
        ], ['accept' => 'text/html', 'referer' => self::ORIGIN . '/contact']), $mailer);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Message sent', (string) $response->getBody());
        self::assertSame(0, $this->submissionCount());
        self::assertSame(0, $mailer->callCount());
    }

    public function testAllowNojsFormWithForgedTokenHtmlPostStillGetsSilentSuccess(): void
    {
        // allow_nojs only waives the check for a *missing* token. A present
        // but forged/too-young token on this path is still a bot signature
        // and falls through to the ordinary silent discard.
        $this->forms->updateForm(
            (int) $this->form['id'],
            'Contact',
            'owner@example.com',
            [self::ORIGIN],
            false,
            30,
            true,
            true // allow_nojs
        );

        $response = $this->handle($this->submitRequest([
            SubmitContext::FIELD_TOKEN => 'not-a-real-token',
            'name'                     => 'Ada',
        ], ['accept' => 'text/html', 'referer' => self::ORIGIN . '/contact']));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Message sent', (string) $response->getBody());
        self::assertSame(0, $this->submissionCount());
    }

    // --- JSON contract is untouched --------------------------------------

    public function testDefaultFormTokenlessJsonPostStillFakeSucceedsAndStoresNothing(): void
    {
        // The honest javascript_required error is HTML-only; the JSON contract
        // for a missing token is completely unchanged regardless of allow_nojs.
        $response = $this->handle($this->submitRequest([
            'name' => 'Ada',
        ]));

        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(['ok' => true], $this->json($response));
        self::assertSame(0, $this->submissionCount());
    }

    public function testAllowNojsFormTokenlessJsonPostStillFakeSucceedsAndStoresNothing(): void
    {
        $this->forms->updateForm(
            (int) $this->form['id'],
            'Contact',
            'owner@example.com',
            [self::ORIGIN],
            false,
            30,
            true,
            true // allow_nojs
        );

        $response = $this->handle($this->submitRequest([
            'name' => 'Ada',
        ]));

        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(['ok' => true], $this->json($response));
        // allow_nojs is an HTML-negotiated-path concept only; the JSON/fetch
        // path (the embed JS) keeps the original silent-discard behaviour.
        self::assertSame(0, $this->submissionCount());
    }

    public function testNoAcceptHeaderKeepsJsonContract(): void
    {
        $token = $this->freshToken();
        $this->clock->advance(3);

        $response = $this->handle($this->submitRequest([
            SubmitContext::FIELD_TOKEN => $token,
            'name'                     => 'Ada',
        ]));

        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(['ok' => true], $this->json($response));
    }

    public function testApplicationJsonAcceptKeepsJsonContract(): void
    {
        $response = $this->handle($this->submitRequest([
            'name' => 'Ada',
        ], ['accept' => 'application/json', 'form_key' => 'osf_unknownkey']));

        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('unknown_form', $this->json($response)['error']['code']);
    }

    public function testBrowserAcceptWithJsonPreferenceStaysJson(): void
    {
        // A fetch that sends both (application/json wins) must not get HTML.
        $token = $this->freshToken();
        $this->clock->advance(3);

        $response = $this->handle($this->submitRequest([
            SubmitContext::FIELD_TOKEN => $token,
            'name'                     => 'Ada',
        ], ['accept' => 'application/json, text/html']));

        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(['ok' => true], $this->json($response));
    }

    // --- Harness ----------------------------------------------------------

    private function app(?MailerInterface $mailer = null): App
    {
        $config = Config::fromEnvironment(['APP_ENV' => 'testing', 'APP_SECRET' => self::SECRET]);

        return AppFactory::create($config, $this->db, $this->dns, $this->clock, $mailer);
    }

    private function handle(ServerRequestInterface $request, ?MailerInterface $mailer = null): ResponseInterface
    {
        return $this->app($mailer)->handle($request);
    }

    private function freshToken(): string
    {
        return (new SubmitToken(self::SECRET, $this->clock, 3, 3600))->issue($this->form['form_key']);
    }

    /**
     * @param array<string, mixed> $fields
     * @param array{origin?: ?string, accept?: string, referer?: string, form_key?: string} $opts
     */
    private function submitRequest(array $fields, array $opts = []): ServerRequestInterface
    {
        $origin = array_key_exists('origin', $opts) ? $opts['origin'] : self::ORIGIN;
        $formKey = $opts['form_key'] ?? $this->form['form_key'];

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/v1/form/' . $formKey . '/submit', ['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded');

        if ($origin !== null) {
            $request = $request->withHeader('Origin', $origin);
        }
        if (isset($opts['accept'])) {
            $request = $request->withHeader('Accept', $opts['accept']);
        }
        if (isset($opts['referer'])) {
            $request = $request->withHeader('Referer', $opts['referer']);
        }

        $request->getBody()->write(http_build_query($fields));
        $request->getBody()->rewind();

        return $request;
    }

    /**
     * @return array<string, mixed>
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
}
