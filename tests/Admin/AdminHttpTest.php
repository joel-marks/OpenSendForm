<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Admin;

use OpenSendForm\AppFactory;
use OpenSendForm\Auth\AdminRepository;
use OpenSendForm\Auth\PasswordHasher;
use OpenSendForm\Auth\RecoveryCodes;
use OpenSendForm\Auth\Totp;
use OpenSendForm\Config;
use OpenSendForm\Storage\Database;
use OpenSendForm\Storage\MigrationRunner;
use OpenSendForm\Tests\Support\FakeSession;
use OpenSendForm\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * End-to-end coverage of the admin auth routes, driven through the app
 * factory against an in-memory database with an array-backed session and a
 * fixed clock. No native session, wall-clock or network is touched.
 */
final class AdminHttpTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery-staple';
    private const T0 = 1_700_000_000;

    private Database $db;
    private FakeSession $session;
    private FixedClock $clock;
    private App $app;
    private AdminRepository $admins;
    private Totp $totp;

    protected function setUp(): void
    {
        $this->db = Database::connect('sqlite::memory:');
        (new MigrationRunner($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();

        $this->session = new FakeSession();
        $this->clock = new FixedClock(self::T0);
        $this->totp = new Totp();

        $hasher = new PasswordHasher(PASSWORD_BCRYPT);
        $this->admins = new AdminRepository($this->db, $hasher, new RecoveryCodes($hasher));

        $config = Config::fromValues(['APP_ENV' => 'dev', 'APP_SECRET' => 'admin-http-secret']);
        $this->app = AppFactory::create($config, $this->db, null, $this->clock, null, null, $this->session);
    }

    // --- Login page & auth ------------------------------------------------

    public function testLoginPageRenders(): void
    {
        $response = $this->get('/admin/login');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<form method="post" action="/admin/login">', (string) $response->getBody());
    }

    public function testProtectedRouteRedirectsWhenLoggedOut(): void
    {
        $response = $this->get('/admin');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/login', $response->getHeaderLine('Location'));
    }

    public function testSecurityHeadersPresentOnAdminResponses(): void
    {
        $response = $this->get('/admin/login');

        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testWrongCredentialsShowGenericError(): void
    {
        $this->admins->createAdmin('boss@example.com', 'The Boss', self::PASSWORD);
        $csrf = $this->csrfFrom($this->get('/admin/login'));

        $response = $this->post('/admin/login', [
            '_csrf'    => $csrf,
            'email'    => 'boss@example.com',
            'password' => 'wrong-password',
        ]);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('Invalid email or password.', (string) $response->getBody());
        self::assertFalse($this->session->has('auth.admin_id'));
    }

    public function testGoodCredentialsWithoutTotpReachDashboard(): void
    {
        $this->admins->createAdmin('boss@example.com', 'The Boss', self::PASSWORD);
        $csrf = $this->csrfFrom($this->get('/admin/login'));

        $login = $this->post('/admin/login', [
            '_csrf'    => $csrf,
            'email'    => 'boss@example.com',
            'password' => self::PASSWORD,
        ]);

        self::assertSame(302, $login->getStatusCode());
        self::assertSame('/admin', $login->getHeaderLine('Location'));

        $dashboard = $this->get('/admin');
        self::assertSame(200, $dashboard->getStatusCode());
        self::assertStringContainsString('The Boss', (string) $dashboard->getBody());
    }

    public function testGoodCredentialsWithTotpGateThenDashboard(): void
    {
        $admin = $this->admins->createAdmin('boss@example.com', 'The Boss', self::PASSWORD);
        $secret = $this->totp->generateSecret();
        $this->admins->setTotp($admin['id'], $secret);
        $this->admins->enableTotp($admin['id']);

        $csrf = $this->csrfFrom($this->get('/admin/login'));
        $login = $this->post('/admin/login', [
            '_csrf'    => $csrf,
            'email'    => 'boss@example.com',
            'password' => self::PASSWORD,
        ]);

        // Password accepted but not yet on the dashboard.
        self::assertSame(302, $login->getStatusCode());
        self::assertSame('/admin/totp', $login->getHeaderLine('Location'));
        self::assertSame(302, $this->get('/admin')->getStatusCode());

        $totpPage = $this->get('/admin/totp');
        self::assertSame(200, $totpPage->getStatusCode());

        $verify = $this->post('/admin/totp', [
            '_csrf' => $this->csrfFrom($totpPage),
            'code'  => $this->totp->codeAt($secret, self::T0),
        ]);
        self::assertSame(302, $verify->getStatusCode());
        self::assertSame('/admin', $verify->getHeaderLine('Location'));

        self::assertSame(200, $this->get('/admin')->getStatusCode());
    }

    public function testLogoutDestroysSession(): void
    {
        $this->admins->createAdmin('boss@example.com', 'The Boss', self::PASSWORD);
        $csrf = $this->csrfFrom($this->get('/admin/login'));
        $this->post('/admin/login', [
            '_csrf'    => $csrf,
            'email'    => 'boss@example.com',
            'password' => self::PASSWORD,
        ]);
        self::assertSame(200, $this->get('/admin')->getStatusCode());

        $logout = $this->post('/admin/logout', ['_csrf' => $this->csrfFrom($this->get('/admin'))]);
        self::assertSame(302, $logout->getStatusCode());
        self::assertSame('/admin/login', $logout->getHeaderLine('Location'));
        self::assertGreaterThan(0, $this->session->destroyCount());

        // Protected route bounces again after logout.
        self::assertSame(302, $this->get('/admin')->getStatusCode());
    }

    // --- CSRF -------------------------------------------------------------

    public function testLoginRejectsMissingCsrfToken(): void
    {
        $this->admins->createAdmin('boss@example.com', 'The Boss', self::PASSWORD);
        // Prime the session token, then omit it from the POST.
        $this->get('/admin/login');

        $response = $this->post('/admin/login', [
            'email'    => 'boss@example.com',
            'password' => self::PASSWORD,
        ]);

        self::assertSame(400, $response->getStatusCode());
        self::assertFalse($this->session->has('auth.admin_id'));
    }

    public function testLoginRejectsWrongCsrfToken(): void
    {
        $this->admins->createAdmin('boss@example.com', 'The Boss', self::PASSWORD);
        $this->get('/admin/login');

        $response = $this->post('/admin/login', [
            '_csrf'    => 'a-forged-token',
            'email'    => 'boss@example.com',
            'password' => self::PASSWORD,
        ]);

        self::assertSame(400, $response->getStatusCode());
        self::assertFalse($this->session->has('auth.admin_id'));
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
        $matched = preg_match(
            '/name="_csrf" value="([a-f0-9]+)"/',
            (string) $response->getBody(),
            $m
        );
        self::assertSame(1, $matched, 'Expected a CSRF token in the response body.');

        return $m[1];
    }
}
