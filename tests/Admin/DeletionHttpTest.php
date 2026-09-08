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
use OpenSendForm\Submission\SubmissionRepository;
use OpenSendForm\Tests\Support\AdminUiFieldWrapperAssertions;
use OpenSendForm\Tests\Support\FakeSession;
use OpenSendForm\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * HTTP-level coverage of the form + submission deletion flows: the row/bulk
 * Delete controls, the GET confirmation pages (with their exact counts and
 * scope), CSRF enforcement, cascade delete, filter preservation and the
 * field-wrapper audit on the new confirm pages. Driven through the app factory
 * against an in-memory database with a fixed clock and array-backed session.
 */
final class DeletionHttpTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery-staple';
    private const T0 = 1_700_000_000;

    private Database $db;
    private FakeSession $session;
    private App $app;
    private FormRepository $forms;
    private SubmissionRepository $subs;

    protected function setUp(): void
    {
        $this->db = Database::connect('sqlite::memory:');
        (new MigrationRunner($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();

        $this->session = new FakeSession();
        $clock = new FixedClock(self::T0);

        $hasher = new PasswordHasher(PASSWORD_BCRYPT);
        $admins = new AdminRepository($this->db, $hasher, new RecoveryCodes($hasher));
        $admins->createAdmin('boss@example.com', 'The Boss', self::PASSWORD);

        $this->forms = new FormRepository($this->db);
        $this->subs = new SubmissionRepository($this->db);

        $config = Config::fromValues(['APP_ENV' => 'dev', 'APP_SECRET' => 'deletion-secret']);
        $this->app = AppFactory::create($config, $this->db, null, $clock, null, null, $this->session);

        $this->login();
    }

    // --- Form deletion ----------------------------------------------------

    public function testFormsListShowsDangerDeleteControl(): void
    {
        $form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);

        $body = (string) $this->get('/admin/forms')->getBody();
        self::assertMatchesRegularExpression(
            '#<a href="/admin/forms/' . $form['id'] . '/delete"[^>]*class="osf-danger osf-btn-sm"#',
            $body
        );
    }

    public function testFormDeleteConfirmStatesNameKeyAndExactSubmissionCount(): void
    {
        $form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $this->subs->recordSubmission($form['id'], '203.0.113.7', null, null);
        $this->subs->recordSubmission($form['id'], '203.0.113.7', null, null);

        $body = (string) $this->get('/admin/forms/' . $form['id'] . '/delete')->getBody();
        self::assertStringContainsString('Contact', $body);
        self::assertStringContainsString($form['form_key'], $body);
        self::assertStringContainsString('2 stored', $body);
        self::assertStringContainsString('cannot be undone', $body);
    }

    public function testFormDeleteConfirmPhrasesZeroNaturally(): void
    {
        $form = $this->forms->createForm('Empty', 'owner@example.com', ['https://example.com']);

        $body = (string) $this->get('/admin/forms/' . $form['id'] . '/delete')->getBody();
        self::assertStringContainsString('0 stored submissions', $body);
    }

    public function testFormDeletePostWithoutCsrfIsRejected(): void
    {
        $form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);

        $response = $this->post('/admin/forms/' . $form['id'] . '/delete', ['_csrf' => 'wrong']);

        self::assertSame(302, $response->getStatusCode());
        self::assertNotNull($this->forms->findById($form['id']), 'form must survive a CSRF failure');
        self::assertStringContainsString('session expired', (string) $this->get('/admin/forms')->getBody());
    }

    public function testFormDeleteCascadesAndFlashesBothCounts(): void
    {
        $form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $other = $this->forms->createForm('Other', 'other@example.com', ['https://other.example.com']);
        $this->subs->recordSubmission($form['id'], '203.0.113.7', null, null);
        $this->subs->recordSubmission($form['id'], '203.0.113.7', null, null);
        $this->subs->recordSubmission($other['id'], '203.0.113.7', null, null);

        $csrf = $this->csrfFrom($this->get('/admin/forms/' . $form['id'] . '/delete'));
        $response = $this->post('/admin/forms/' . $form['id'] . '/delete', ['_csrf' => $csrf]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/forms', $response->getHeaderLine('Location'));
        self::assertNull($this->forms->findById($form['id']));
        self::assertSame(0, $this->subs->countFiltered(null, $form['id']));
        // The other form and its submission are untouched.
        self::assertNotNull($this->forms->findById($other['id']));
        self::assertSame(1, $this->subs->countFiltered(null, $other['id']));

        $body = (string) $this->get('/admin/forms')->getBody();
        self::assertStringContainsString('Deleted form &quot;Contact&quot; and its 2 stored submissions.', $body);
    }

    public function testDisabledFormIsAlsoDeletable(): void
    {
        $form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $this->forms->setActive($form['id'], false);

        $csrf = $this->csrfFrom($this->get('/admin/forms/' . $form['id'] . '/delete'));
        $response = $this->post('/admin/forms/' . $form['id'] . '/delete', ['_csrf' => $csrf]);

        self::assertSame(302, $response->getStatusCode());
        self::assertNull($this->forms->findById($form['id']));
    }

    public function testFormDeleteConfirmWrapsControlsInTheFieldPattern(): void
    {
        $form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        AdminUiFieldWrapperAssertions::assertNoOrphanControls(
            (string) $this->get('/admin/forms/' . $form['id'] . '/delete')->getBody(),
            'form delete confirm'
        );
    }

    // --- Submission deletion (single) -------------------------------------

    public function testSubmissionsListShowsDangerDeleteControl(): void
    {
        $form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $id = $this->subs->recordSubmission($form['id'], '203.0.113.7', null, null);

        $body = (string) $this->get('/admin/submissions')->getBody();
        self::assertMatchesRegularExpression(
            '#<a href="/admin/submissions/' . $id . '/delete"[^>]*class="osf-danger osf-btn-sm"#',
            $body
        );
    }

    public function testSubmissionDeleteConfirmStatesFormDateAndStatus(): void
    {
        $form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $id = $this->subs->recordSubmission($form['id'], '203.0.113.7', null, null, null, 'failed');

        $body = (string) $this->get('/admin/submissions/' . $id . '/delete')->getBody();
        self::assertStringContainsString('#' . $id, $body);
        self::assertStringContainsString('Contact', $body);
        self::assertStringContainsString('failed', $body);
    }

    public function testSubmissionDeletePostWithoutCsrfIsRejected(): void
    {
        $form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $id = $this->subs->recordSubmission($form['id'], '203.0.113.7', null, null);

        $this->post('/admin/submissions/' . $id . '/delete', ['_csrf' => 'wrong']);

        self::assertNotNull($this->subs->findById($id));
    }

    public function testSubmissionDeleteRemovesRowAndPreservesFilter(): void
    {
        $form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $id = $this->subs->recordSubmission($form['id'], '203.0.113.7', null, null, null, 'failed');

        $csrf = $this->csrfFrom($this->get('/admin/submissions?status=failed'));
        $response = $this->post('/admin/submissions/' . $id . '/delete', [
            '_csrf'  => $csrf,
            'status' => 'failed',
            'form'   => '',
            'page'   => '1',
        ]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/submissions?status=failed', $response->getHeaderLine('Location'));
        self::assertNull($this->subs->findById($id));
    }

    // --- Submission deletion (bulk / delete-all) --------------------------

    public function testDeleteAllControlLabelReflectsStatusFilterAndCount(): void
    {
        $form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $this->subs->recordSubmission($form['id'], '203.0.113.7', null, null, null, 'failed');
        $this->subs->recordSubmission($form['id'], '203.0.113.7', null, null, null, 'failed');
        $this->subs->recordSubmission($form['id'], '203.0.113.7', null, null, null, 'sent');

        // Unfiltered: all three.
        $body = (string) $this->get('/admin/submissions')->getBody();
        self::assertStringContainsString('Delete all submissions (3)', $body);

        // Status-filtered: only the two failed.
        $body = (string) $this->get('/admin/submissions?status=failed')->getBody();
        self::assertStringContainsString('Delete all failed submissions (2)', $body);
        self::assertStringContainsString('/admin/submissions/delete-all?status=failed', $body);
    }

    public function testDeleteAllControlDisabledWhenNothingInScope(): void
    {
        // No submissions at all: the control is a disabled button, not a link.
        $body = (string) $this->get('/admin/submissions')->getBody();
        self::assertStringContainsString('disabled title="No submissions to delete."', $body);
        self::assertStringNotContainsString('href="/admin/submissions/delete-all"', $body);
    }

    public function testDeleteAllConfirmStatesScopeAndCount(): void
    {
        $form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $this->subs->recordSubmission($form['id'], '203.0.113.7', null, null, null, 'failed');
        $this->subs->recordSubmission($form['id'], '203.0.113.7', null, null, null, 'sent');

        $all = (string) $this->get('/admin/submissions/delete-all')->getBody();
        self::assertStringContainsString('every', $all);
        self::assertStringContainsString('2', $all);

        $scoped = (string) $this->get('/admin/submissions/delete-all?status=failed')->getBody();
        self::assertStringContainsString('failed', $scoped);
        self::assertStringContainsString('1', $scoped);
    }

    public function testDeleteAllConfirmBouncesWhenZero(): void
    {
        $response = $this->get('/admin/submissions/delete-all');
        // Nothing in scope: bounce back to the list rather than show a dead-end
        // confirm page, and surface the explanatory flash on that list.
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/submissions', $response->getHeaderLine('Location'));
        self::assertStringContainsString(
            'no submissions to delete',
            strtolower((string) $this->get('/admin/submissions')->getBody())
        );
    }

    public function testDeleteAllUnfilteredPostWithoutCsrfIsRejected(): void
    {
        $form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $this->subs->recordSubmission($form['id'], '203.0.113.7', null, null);

        $this->post('/admin/submissions/delete-all', ['_csrf' => 'wrong', 'status' => '']);

        self::assertSame(1, $this->subs->countFiltered(null, null), 'nothing deleted on CSRF failure');
    }

    public function testDeleteAllUnfilteredRemovesEverythingAndFlashesCount(): void
    {
        $form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $this->subs->recordSubmission($form['id'], '203.0.113.7', null, null, null, 'failed');
        $this->subs->recordSubmission($form['id'], '203.0.113.7', null, null, null, 'sent');

        $csrf = $this->csrfFrom($this->get('/admin/submissions/delete-all'));
        $response = $this->post('/admin/submissions/delete-all', ['_csrf' => $csrf, 'status' => '']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(0, $this->subs->countFiltered(null, null));
        self::assertStringContainsString('Deleted 2 submissions.', (string) $this->get('/admin/submissions')->getBody());
    }

    public function testDeleteAllScopedToStatusRemovesOnlyThatStatus(): void
    {
        $form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $this->subs->recordSubmission($form['id'], '203.0.113.7', null, null, null, 'failed');
        $this->subs->recordSubmission($form['id'], '203.0.113.7', null, null, null, 'failed');
        $this->subs->recordSubmission($form['id'], '203.0.113.7', null, null, null, 'sent');

        $csrf = $this->csrfFrom($this->get('/admin/submissions/delete-all?status=failed'));
        $response = $this->post('/admin/submissions/delete-all', ['_csrf' => $csrf, 'status' => 'failed']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(0, $this->subs->countByStatus('failed'));
        self::assertSame(1, $this->subs->countByStatus('sent'));
        self::assertStringContainsString('Deleted 2 failed submissions.', (string) $this->get('/admin/submissions')->getBody());
    }

    public function testConfirmPagesWrapControlsInTheFieldPattern(): void
    {
        $form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $id = $this->subs->recordSubmission($form['id'], '203.0.113.7', null, null);

        AdminUiFieldWrapperAssertions::assertNoOrphanControls(
            (string) $this->get('/admin/submissions/' . $id . '/delete')->getBody(),
            'submission delete confirm'
        );
        AdminUiFieldWrapperAssertions::assertNoOrphanControls(
            (string) $this->get('/admin/submissions/delete-all')->getBody(),
            'submissions delete-all confirm'
        );
    }

    // --- Helpers ----------------------------------------------------------

    private function login(): void
    {
        $csrf = $this->csrfFrom($this->get('/admin/login'));
        $this->post('/admin/login', [
            '_csrf'    => $csrf,
            'email'    => 'boss@example.com',
            'password' => self::PASSWORD,
        ]);
    }

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
}
