<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Install;

use OpenSendForm\AppFactory;
use OpenSendForm\Config;
use OpenSendForm\Install\Paths;
use OpenSendForm\Tests\Support\FakeSession;
use OpenSendForm\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * End-to-end coverage of the browser installer at the HTTP level, driven
 * through the app factory against a throwaway temp directory (SQLite) with an
 * array-backed session and a fixed clock. Covers the full happy-path wizard,
 * step-order enforcement, the not-installed → /install redirects and the
 * installed → 404 direction, and the design-system contract (CSP, pico.css,
 * no inline handlers). Never touches the real project's var/ or a network.
 */
final class InstallerHttpTest extends TestCase
{
    private const T0 = 1_700_000_000;
    private const ADMIN_EMAIL = 'boss@example.com';
    private const ADMIN_PASSWORD = 'a-strong-password-1';

    private string $base;
    private Paths $paths;
    private FakeSession $session;
    private App $app;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/osf_http_' . bin2hex(random_bytes(6));
        mkdir($this->base . '/var/data', 0775, true);

        $this->paths = Paths::underBase($this->base, dirname(__DIR__, 2) . '/migrations');
        $this->session = new FakeSession();

        // The app's own database is the SAME SQLite file the installer will
        // create/migrate, mirroring production (post-install, config points the
        // app at the installed DB), so a login after install sees the new admin.
        $dbFile = $this->base . '/var/data/opensendform.sqlite';
        $config = Config::fromValues([
            'APP_ENV'    => 'dev',
            'APP_SECRET' => 'installer-http-secret',
            'DB_DSN'     => 'sqlite:' . $dbFile,
        ]);

        $this->app = AppFactory::create(
            $config,
            null,
            null,
            new FixedClock(self::T0),
            null,
            null,
            $this->session,
            $this->paths
        );
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->base);
    }

    // --- Happy path -------------------------------------------------------

    public function testFullWizardInstallsAndAdminCanLogIn(): void
    {
        // Step 1: welcome + requirements. In this environment nothing fails, so
        // Continue links onward rather than being disabled.
        $welcome = $this->get('/install');
        self::assertSame(200, $welcome->getStatusCode());
        $welcomeBody = (string) $welcome->getBody();
        self::assertStringContainsString('Hosting check', $welcomeBody);
        self::assertStringContainsString('href="/install/database"', $welcomeBody);
        self::assertStringNotContainsString('osf-disabled-link', $welcomeBody);

        // Step 2: choose the built-in SQLite database.
        $dbPage = $this->get('/install/database');
        self::assertSame(200, $dbPage->getStatusCode());
        $dbSubmit = $this->post('/install/database', [
            '_csrf'     => $this->csrfFrom($dbPage),
            'db_driver' => 'sqlite',
        ]);
        self::assertSame(302, $dbSubmit->getStatusCode());
        self::assertSame('/install/admin', $dbSubmit->getHeaderLine('Location'));

        // Step 3: first admin.
        $adminPage = $this->get('/install/admin');
        self::assertSame(200, $adminPage->getStatusCode());
        $adminSubmit = $this->post('/install/admin', [
            '_csrf'            => $this->csrfFrom($adminPage),
            'name'             => 'The Boss',
            'email'            => self::ADMIN_EMAIL,
            'password'         => self::ADMIN_PASSWORD,
            'password_confirm' => self::ADMIN_PASSWORD,
        ]);
        self::assertSame(302, $adminSubmit->getStatusCode());
        self::assertSame('/install/finish', $adminSubmit->getHeaderLine('Location'));

        // Step 4: review + commit.
        $finishPage = $this->get('/install/finish');
        self::assertSame(200, $finishPage->getStatusCode());
        self::assertStringContainsString('Built-in database', (string) $finishPage->getBody());

        $finishSubmit = $this->post('/install/finish', ['_csrf' => $this->csrfFrom($finishPage)]);
        self::assertSame(302, $finishSubmit->getStatusCode());
        self::assertSame('/install/done', $finishSubmit->getHeaderLine('Location'));

        // Step 5: success screen and, on disk, an installed app.
        $done = $this->get('/install/done');
        self::assertSame(200, $done->getStatusCode());
        self::assertStringContainsString('installed', (string) $done->getBody());
        self::assertStringContainsString('var/install.lock', (string) $done->getBody());
        // Post-install handoff: the done screen points at the mail-setup wizard.
        self::assertStringContainsString('/admin/mail', (string) $done->getBody());

        self::assertTrue($this->paths->isInstalled());

        // Config written with a real 64-hex secret and mail off.
        $loaded = Config::fromFile($this->paths->configPath);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $loaded->appSecret());
        self::assertFalse($loaded->mailEnabled());

        // The installer routes are now closed; the app is reachable.
        self::assertSame(404, $this->get('/install')->getStatusCode());
        self::assertSame(404, $this->get('/install/database')->getStatusCode());
        self::assertSame(200, $this->get('/admin/login')->getStatusCode());

        // The created admin can sign in (the app reads the installed DB).
        $login = $this->post('/admin/login', [
            '_csrf'    => $this->csrfFrom($this->get('/admin/login')),
            'email'    => self::ADMIN_EMAIL,
            'password' => self::ADMIN_PASSWORD,
        ]);
        self::assertSame(302, $login->getStatusCode());
        self::assertSame('/admin', $login->getHeaderLine('Location'));
    }

    // --- Step-order enforcement -------------------------------------------

    public function testJumpingToAdminWithoutDatabaseRedirectsBack(): void
    {
        $response = $this->get('/install/admin');
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/install/database', $response->getHeaderLine('Location'));
    }

    public function testJumpingToFinishWithoutAdminRedirectsBack(): void
    {
        // Complete the DB step only, then try to skip to finish.
        $dbPage = $this->get('/install/database');
        $this->post('/install/database', [
            '_csrf'     => $this->csrfFrom($dbPage),
            'db_driver' => 'sqlite',
        ]);

        $response = $this->get('/install/finish');
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/install/admin', $response->getHeaderLine('Location'));
    }

    public function testFinishWithoutAnyStepsRedirectsToDatabase(): void
    {
        $response = $this->post('/install/finish', ['_csrf' => $this->primeCsrf()]);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/install/database', $response->getHeaderLine('Location'));
        self::assertFalse($this->paths->isInstalled(), 'nothing committed');
    }

    // --- CSRF & secret handling -------------------------------------------

    public function testDatabaseStepRejectsBadCsrf(): void
    {
        $this->get('/install/database');
        $response = $this->post('/install/database', ['db_driver' => 'sqlite']);
        self::assertSame(400, $response->getStatusCode());
        self::assertNull($this->session->get('install.db'));
    }

    public function testAdminErrorNeverEchoesPassword(): void
    {
        // Pass the DB step first.
        $dbPage = $this->get('/install/database');
        $this->post('/install/database', [
            '_csrf'     => $this->csrfFrom($dbPage),
            'db_driver' => 'sqlite',
        ]);

        $adminPage = $this->get('/install/admin');
        $secret = 'my-secret-password-9';
        $response = $this->post('/install/admin', [
            '_csrf'            => $this->csrfFrom($adminPage),
            'name'             => 'The Boss',
            'email'            => self::ADMIN_EMAIL,
            'password'         => $secret,
            'password_confirm' => 'does-not-match-9',
        ]);

        self::assertSame(422, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('do not match', $body);
        self::assertStringNotContainsString($secret, $body, 'password must never be echoed back');
        // Non-secret fields are preserved.
        self::assertStringContainsString('value="' . self::ADMIN_EMAIL . '"', $body);
        self::assertStringContainsString('value="The Boss"', $body);
    }

    // --- Installed-state routing (not-installed direction) ----------------

    public function testNonInstallRoutesRedirectWhenNotInstalled(): void
    {
        foreach (['/health', '/admin', '/admin/login'] as $path) {
            $response = $this->get($path);
            self::assertSame(302, $response->getStatusCode(), $path);
            self::assertSame('/install', $response->getHeaderLine('Location'), $path);
        }
    }

    public function testPublicApiRedirectsWhenNotInstalled(): void
    {
        $response = $this->app->handle(
            (new ServerRequestFactory())->createServerRequest(
                'POST',
                '/v1/form/some-key/submit',
                ['REMOTE_ADDR' => '203.0.113.5']
            )
        );
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/install', $response->getHeaderLine('Location'));
    }

    // --- Design-system contract -------------------------------------------

    public function testInstallScreensUseThePicoDesignSystemAndCarryCsp(): void
    {
        $response = $this->get('/install');
        self::assertStringContainsString('/assets/vendor/pico.min.css', (string) $response->getBody());
        self::assertStringContainsString('/assets/admin.css', (string) $response->getBody());
        self::assertNotSame('', $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testInstallTemplatesHaveNoInlineHandlersOrScripts(): void
    {
        $dir = dirname(__DIR__, 2) . '/templates/install';
        foreach (glob($dir . '/*.php') as $file) {
            $html = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression(
                '/\son[a-z]+\s*=\s*["\']/i',
                $html,
                "Inline event handler in {$file}"
            );
            if (preg_match_all('/<script\b[^>]*>/i', $html, $tags)) {
                foreach ($tags[0] as $tag) {
                    self::assertStringContainsString('src=', $tag, "Inline <script> in {$file}: {$tag}");
                }
            }
        }
    }

    // --- Helpers ----------------------------------------------------------

    private function get(string $path): ResponseInterface
    {
        return $this->app->handle($this->request('GET', $path));
    }

    /**
     * @param array<string, string> $body
     */
    private function post(string $path, array $body): ResponseInterface
    {
        return $this->app->handle($this->request('POST', $path)->withParsedBody($body));
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

    private function primeCsrf(): string
    {
        // Render any install page to seed a session CSRF token, then read it.
        return $this->csrfFrom($this->get('/install/database'));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
