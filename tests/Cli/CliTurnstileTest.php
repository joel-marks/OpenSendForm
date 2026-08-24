<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Cli;

use OpenSendForm\Form\FormRepository;
use OpenSendForm\Storage\Database;
use PHPUnit\Framework\TestCase;

/**
 * Exercises `bin/osf form:turnstile` for real by shelling out against a
 * throwaway SQLite database (DB_DSN override), then reading the result back
 * through the repository. Covers the enable/disable round-trip and the
 * validation guards. Never touches the dev database or any network.
 */
final class CliTurnstileTest extends TestCase
{
    private string $dbPath;
    private string $osf;

    protected function setUp(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'osf_cli_');
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

    public function testEnableThenDisableRoundTrip(): void
    {
        $created = $this->osf('form:create', '--name=Contact', '--recipient=owner@example.com', '--origin=https://example.com');
        self::assertSame(0, $created['code'], $created['stderr']);
        self::assertMatchesRegularExpression('/Created form #(\d+)/', $created['stdout']);

        $id = $this->onlyFormId();

        $enable = $this->osf('form:turnstile', (string) $id, '--sitekey=0xSITE', '--secret=0xSECRET');
        self::assertSame(0, $enable['code'], $enable['stderr']);
        self::assertStringContainsString("Turnstile enabled for form #{$id}.", $enable['stdout']);

        $enabled = $this->repo()->findById($id);
        self::assertSame('0xSITE', $enabled['turnstile_sitekey']);
        self::assertSame('0xSECRET', $enabled['turnstile_secret']);

        $disable = $this->osf('form:turnstile', (string) $id, '--disable');
        self::assertSame(0, $disable['code'], $disable['stderr']);
        self::assertStringContainsString("Turnstile disabled for form #{$id}.", $disable['stdout']);

        $disabled = $this->repo()->findById($id);
        self::assertNull($disabled['turnstile_sitekey']);
        self::assertNull($disabled['turnstile_secret']);
    }

    public function testEnableRequiresBothKeys(): void
    {
        $this->osf('form:create', '--name=Contact', '--recipient=owner@example.com', '--origin=https://example.com');
        $id = $this->onlyFormId();

        $result = $this->osf('form:turnstile', (string) $id, '--sitekey=0xSITE');

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('requires --sitekey and --secret', $result['stderr']);

        // Nothing was written.
        $row = $this->repo()->findById($id);
        self::assertNull($row['turnstile_sitekey']);
        self::assertNull($row['turnstile_secret']);
    }

    public function testUnknownFormIdReports(): void
    {
        // A valid (migrated) but empty database: id 999 does not exist.
        $result = $this->osf('form:turnstile', '999', '--sitekey=0xSITE', '--secret=0xSECRET');

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('No form found with ID 999', $result['stderr']);
    }

    // --- Harness ----------------------------------------------------------

    private function repo(): FormRepository
    {
        return new FormRepository(Database::connect('sqlite:' . $this->dbPath));
    }

    private function onlyFormId(): int
    {
        $forms = $this->repo()->listForms();
        self::assertCount(1, $forms);

        return (int) $forms[0]['id'];
    }

    /**
     * @return array{code:int, stdout:string, stderr:string}
     */
    private function osf(string ...$args): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->osf);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = getenv();
        $env['DB_DSN'] = 'sqlite:' . $this->dbPath;
        // Keep the child quiet: no Xdebug step-debug connect attempts/warnings.
        $env['XDEBUG_MODE'] = 'off';

        $process = proc_open($cmd, $descriptors, $pipes, null, $env);
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
