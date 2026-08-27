<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Cli;

use OpenSendForm\Storage\Database;
use OpenSendForm\Storage\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * Exercises `bin/osf migrate` for real by shelling out with an OSF_BASE_DIR
 * pointed at a throwaway directory: refuses to run before the browser
 * installer has produced a config, applies pending migrations to a stale
 * database and reports each one, then reports "up to date" and exits 0 when
 * run again — the fix for the live incident where migration 009 shipped in
 * code but an already-installed database never picked it up.
 */
final class CliMigrateTest extends TestCase
{
    private string $base;
    private string $osf;
    private string $dbPath;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/osf_migrate_' . bin2hex(random_bytes(6));
        mkdir($this->base . '/var/data', 0775, true);
        $this->osf = dirname(__DIR__, 2) . '/bin/osf';
        $this->dbPath = $this->base . '/var/data/test.sqlite';
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->base);
    }

    public function testRefusesToRunBeforeInstall(): void
    {
        $result = $this->osf();

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('not installed', $result['stderr']);
        self::assertFileDoesNotExist($this->dbPath);
    }

    public function testAppliesPendingMigrationsToAStaleDatabaseAndIsIdempotent(): void
    {
        $this->markInstalled();
        // A stale database: only migrations 1-8 have ever run, mirroring an
        // instance installed before migration 009 shipped.
        $this->buildDatabaseAtVersion(8);

        $first = $this->osf();
        self::assertSame(0, $first['code'], $first['stderr']);
        self::assertStringContainsString('Applied 009_form_allow_nojs.sql', $first['stdout']);
        self::assertStringNotContainsString('Applied 001', $first['stdout']);

        $second = $this->osf();
        self::assertSame(0, $second['code'], $second['stderr']);
        self::assertStringContainsString('Already up to date.', $second['stdout']);
    }

    public function testAppliesEveryMigrationOnAFreshInstall(): void
    {
        $this->markInstalled();

        $result = $this->osf();

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString('Applied 001_create_schema_migrations.sql', $result['stdout']);
        self::assertStringContainsString('Applied 009_form_allow_nojs.sql', $result['stdout']);
    }

    private function markInstalled(): void
    {
        file_put_contents($this->base . '/var/config.php', "<?php\nreturn ['DB_DSN' => 'sqlite:{$this->dbPath}'];\n");
        file_put_contents(
            $this->base . '/var/install.lock',
            json_encode(['installed_at' => '2026-08-25 10:00:00', 'version' => '0.0.1']) . "\n"
        );
    }

    /**
     * Build the on-disk database with only migrations 1..$version applied, by
     * running the real MigrationRunner against a temp directory containing
     * copies of just those numbered migration files.
     */
    private function buildDatabaseAtVersion(int $version): void
    {
        $realMigrations = dirname(__DIR__, 2) . '/migrations';
        $partial = $this->base . '/partial-migrations';
        mkdir($partial, 0775, true);

        foreach (glob($realMigrations . '/*.sql') ?: [] as $file) {
            $name = basename($file);
            if (preg_match('/^(\d+)/', $name, $m) && (int) $m[1] <= $version) {
                copy($file, $partial . '/' . $name);
            }
        }

        $db = Database::connect('sqlite:' . $this->dbPath);
        (new MigrationRunner($db, $partial))->migrate();
    }

    /**
     * @return array{code:int, stdout:string, stderr:string}
     */
    private function osf(): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->osf) . ' migrate';

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
