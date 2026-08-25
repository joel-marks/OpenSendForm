<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * Exercises `bin/osf install:status` for real by shelling out with an
 * OSF_BASE_DIR pointed at a throwaway directory, so both the not-installed and
 * installed states can be observed without touching the project's own var/ or
 * a database (the command never connects to one).
 */
final class CliInstallStatusTest extends TestCase
{
    private string $base;
    private string $osf;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/osf_status_' . bin2hex(random_bytes(6));
        mkdir($this->base . '/var/data', 0775, true);
        $this->osf = dirname(__DIR__, 2) . '/bin/osf';
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->base);
    }

    public function testReportsNotInstalled(): void
    {
        $result = $this->osf();

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString('NOT installed', $result['stdout']);
        self::assertStringContainsString('Config file: missing', $result['stdout']);
        self::assertStringContainsString('Lock file:   missing', $result['stdout']);
    }

    public function testReportsInstalledWithLockDetails(): void
    {
        file_put_contents($this->base . '/var/config.php', "<?php\nreturn [];\n");
        file_put_contents(
            $this->base . '/var/install.lock',
            json_encode(['installed_at' => '2026-08-25 10:00:00', 'version' => '0.0.1']) . "\n"
        );

        $result = $this->osf();

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString('Status:      installed', $result['stdout']);
        self::assertStringContainsString('Config file: present', $result['stdout']);
        self::assertStringContainsString('2026-08-25 10:00:00', $result['stdout']);
        self::assertStringContainsString('0.0.1', $result['stdout']);
    }

    /**
     * @return array{code:int, stdout:string, stderr:string}
     */
    private function osf(): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->osf) . ' install:status';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = getenv();
        $env['OSF_BASE_DIR'] = $this->base;
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
