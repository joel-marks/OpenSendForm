<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Cli;

use OpenSendForm\Form\FormRepository;
use OpenSendForm\Storage\Database;
use OpenSendForm\Storage\MigrationRunner;
use OpenSendForm\Submission\SubmissionRepository;
use PHPUnit\Framework\TestCase;

/**
 * Exercises `bin/osf form:delete` and `bin/osf submissions:purge` for real by
 * shelling out against a throwaway SQLite database (DB_DSN override), then
 * reading results back through the repositories. Never touches the dev
 * database or any network.
 */
final class CliDeleteTest extends TestCase
{
    private string $dbPath;
    private string $osf;

    protected function setUp(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'osf_delete_');
        self::assertNotFalse($tmp);
        $this->dbPath = $tmp;
        $this->osf = dirname(__DIR__, 2) . '/bin/osf';
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    // --- form:delete ------------------------------------------------------

    public function testFormDeleteCascadesAndReportsCounts(): void
    {
        $forms = $this->forms();
        $subs = $this->subs();
        $form = $forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $subs->recordSubmission($form['id'], '203.0.113.7', null, null);
        $subs->recordSubmission($form['id'], '203.0.113.7', null, null);

        $result = $this->osf(['form:delete', (string) $form['id']]);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString('Deleted form #' . $form['id'], $result['stdout']);
        self::assertStringContainsString('2 submissions', $result['stdout']);
        self::assertNull($this->forms()->findById($form['id']));
        self::assertSame(0, $this->subs()->countFiltered(null, $form['id']));
    }

    public function testFormDeleteRefusesUnknownId(): void
    {
        $result = $this->osf(['form:delete', '999']);

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('No form found', $result['stderr']);
    }

    public function testFormDeleteRefusesNonNumericId(): void
    {
        $result = $this->osf(['form:delete', 'nope']);

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('requires a numeric form ID', $result['stderr']);
    }

    // --- submissions:purge ------------------------------------------------

    public function testPurgeAllRemovesEverything(): void
    {
        $form = $this->forms()->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $this->subs()->recordSubmission($form['id'], '203.0.113.7', null, null, null, 'failed');
        $this->subs()->recordSubmission($form['id'], '203.0.113.7', null, null, null, 'sent');

        $result = $this->osf(['submissions:purge']);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString('Deleted 2 submissions', $result['stdout']);
        self::assertSame(0, $this->subs()->countFiltered(null, null));
    }

    public function testPurgeByStatusRemovesOnlyThatStatus(): void
    {
        $form = $this->forms()->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $this->subs()->recordSubmission($form['id'], '203.0.113.7', null, null, null, 'failed');
        $this->subs()->recordSubmission($form['id'], '203.0.113.7', null, null, null, 'sent');

        $result = $this->osf(['submissions:purge', '--status=failed']);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString('Deleted 1 failed submission', $result['stdout']);
        self::assertSame(0, $this->subs()->countByStatus('failed'));
        self::assertSame(1, $this->subs()->countByStatus('sent'));
    }

    public function testPurgeByFormRemovesOnlyThatForm(): void
    {
        $a = $this->forms()->createForm('A', 'a@example.com', ['https://a.example.com']);
        $b = $this->forms()->createForm('B', 'b@example.com', ['https://b.example.com']);
        $this->subs()->recordSubmission($a['id'], '203.0.113.7', null, null);
        $this->subs()->recordSubmission($b['id'], '203.0.113.7', null, null);

        $result = $this->osf(['submissions:purge', '--form=' . $a['id']]);

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString('Deleted 1 submission for form #' . $a['id'], $result['stdout']);
        self::assertSame(0, $this->subs()->countFiltered(null, $a['id']));
        self::assertSame(1, $this->subs()->countFiltered(null, $b['id']));
    }

    public function testPurgeByUnknownFormRefused(): void
    {
        $result = $this->osf(['submissions:purge', '--form=999']);

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('No form found', $result['stderr']);
    }

    public function testPurgeRefusesStatusAndFormTogether(): void
    {
        $form = $this->forms()->createForm('Contact', 'owner@example.com', ['https://example.com']);

        $result = $this->osf(['submissions:purge', '--status=failed', '--form=' . $form['id']]);

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('does not support --status and --form together', $result['stderr']);
    }

    // --- Helpers ----------------------------------------------------------

    private function db(): Database
    {
        $db = Database::connect('sqlite:' . $this->dbPath);
        (new MigrationRunner($db, dirname(__DIR__, 2) . '/migrations'))->migrate();

        return $db;
    }

    private function forms(): FormRepository
    {
        return new FormRepository($this->db());
    }

    private function subs(): SubmissionRepository
    {
        return new SubmissionRepository($this->db());
    }

    /**
     * @param array<int, string> $args
     * @return array{code:int, stdout:string, stderr:string}
     */
    private function osf(array $args): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->osf);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = getenv();
        $env['DB_DSN'] = 'sqlite:' . $this->dbPath;
        $env['XDEBUG_MODE'] = 'off';

        $process = proc_open($cmd, $descriptors, $pipes, null, $env);
        self::assertIsResource($process);

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
