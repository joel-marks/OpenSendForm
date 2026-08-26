<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Admin;

use OpenSendForm\AppFactory;
use OpenSendForm\Auth\AdminRepository;
use OpenSendForm\Auth\PasswordHasher;
use OpenSendForm\Auth\RecoveryCodes;
use OpenSendForm\Config;
use OpenSendForm\Form\FormRepository;
use OpenSendForm\Storage\Database;
use OpenSendForm\Storage\MigrationRunner;
use OpenSendForm\Tests\Support\FakeSession;
use OpenSendForm\Tests\Support\FixedClock;
use OpenSendForm\Version;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The per-form "Embed code" panel on the admin form-edit screen (Increment 7).
 *
 * The panel shows the exact copy-paste snippet — a plain HTML form whose action
 * is the submit URL, plus the one <script> line — with the form's real key and
 * the current installation URL filled in. It appears only for a saved form.
 */
final class EmbedPanelTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery-staple';
    private const HOST = 'http://forms.example.test';

    private Database $db;
    private FakeSession $session;
    private App $app;
    private AdminRepository $admins;
    private FormRepository $forms;

    protected function setUp(): void
    {
        $this->db = Database::connect('sqlite::memory:');
        (new MigrationRunner($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();

        $this->session = new FakeSession();
        $hasher = new PasswordHasher(PASSWORD_BCRYPT);
        $this->admins = new AdminRepository($this->db, $hasher, new RecoveryCodes($hasher));
        $this->forms = new FormRepository($this->db);

        $config = Config::fromValues(['APP_ENV' => 'dev', 'APP_SECRET' => 'embed-panel-secret']);
        $this->app = AppFactory::create($config, $this->db, null, new FixedClock(1_700_000_000), null, null, $this->session);
    }

    public function testEditScreenShowsEmbedSnippetWithKeyAndUrl(): void
    {
        $form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $key = (string) $form['form_key'];
        $this->login();

        $body = (string) $this->get(self::HOST . '/admin/forms/' . $form['id'] . '/edit')->getBody();

        self::assertStringContainsString('Embed code', $body);
        // The snippet's form action + data attributes carry the real key and host.
        self::assertStringContainsString('action=&quot;' . self::HOST . '/v1/form/' . $key . '/submit&quot;', $body);
        self::assertStringContainsString('data-osf-key=&quot;' . $key . '&quot;', $body);
        self::assertStringContainsString('data-osf-url=&quot;' . self::HOST . '&quot;', $body);
        // The versioned <script> line, cache-busted by ?v=.
        self::assertStringContainsString(self::HOST . '/embed/osf.js?v=' . Version::STRING, $body);
        // A copy button reuses the existing [data-copy] enhancement.
        self::assertStringContainsString('data-copy=', $body);
    }

    public function testNewFormScreenHasNoEmbedPanel(): void
    {
        $this->login();

        $body = (string) $this->get(self::HOST . '/admin/forms/new')->getBody();

        self::assertStringNotContainsString('Embed code', $body);
        self::assertStringNotContainsString('embed/osf.js', $body);
    }

    // --- Helpers ----------------------------------------------------------

    private function login(): void
    {
        $this->admins->createAdmin('boss@example.com', 'The Boss', self::PASSWORD);
        $csrf = $this->csrfFrom($this->get(self::HOST . '/admin/login'));
        $this->app->handle(
            $this->request('POST', self::HOST . '/admin/login')->withParsedBody([
                '_csrf'    => $csrf,
                'email'    => 'boss@example.com',
                'password' => self::PASSWORD,
            ])
        );
    }

    private function get(string $path): ResponseInterface
    {
        return $this->app->handle($this->request('GET', $path));
    }

    private function request(string $method, string $path): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest(
            $method,
            $path,
            ['REMOTE_ADDR' => '203.0.113.5']
        );
    }

    private function csrfFrom(ResponseInterface $response): string
    {
        $matched = preg_match('/name="_csrf" value="([a-f0-9]+)"/', (string) $response->getBody(), $m);
        self::assertSame(1, $matched, 'Expected a CSRF token in the response body.');

        return $m[1];
    }
}
