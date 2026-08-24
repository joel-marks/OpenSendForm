<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Cli;

use OpenSendForm\Auth\AdminRepository;
use OpenSendForm\Auth\PasswordHasher;
use OpenSendForm\Auth\RecoveryCodes;
use OpenSendForm\Storage\Database;
use PHPUnit\Framework\TestCase;

/**
 * Exercises `bin/osf admin:create` for real by shelling out against a
 * throwaway SQLite database (DB_DSN override), feeding the interactive
 * password prompts over stdin, then reading the result back through the
 * repository. STDIN is a pipe (not a TTY), so the command takes its visible
 * fallback path. Never touches the dev database or any network.
 */
final class CliAdminTest extends TestCase
{
    private string $dbPath;
    private string $osf;

    protected function setUp(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'osf_admin_');
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

    public function testCreatesAdminWithValidPassword(): void
    {
        $result = $this->osf(
            ['admin:create', '--email=boss@example.com', '--name=The Boss'],
            "a-strong-password\na-strong-password\n"
        );

        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertStringContainsString('Created admin', $result['stdout']);
        // Never echoes the password.
        self::assertStringNotContainsString('a-strong-password', $result['stdout']);

        $admin = $this->repo()->findByEmail('boss@example.com');
        self::assertNotNull($admin);
        self::assertSame('The Boss', $admin['display_name']);
        self::assertTrue((new PasswordHasher())->verify('a-strong-password', $admin['password_hash']));
    }

    public function testRefusesWeakPassword(): void
    {
        $result = $this->osf(
            ['admin:create', '--email=boss@example.com', '--name=Boss'],
            "short\nshort\n"
        );

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('at least 12 characters', $result['stderr']);
        self::assertNull($this->repo()->findByEmail('boss@example.com'));
    }

    public function testRefusesMismatchedPasswords(): void
    {
        $result = $this->osf(
            ['admin:create', '--email=boss@example.com', '--name=Boss'],
            "a-strong-password\na-different-password\n"
        );

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('do not match', $result['stderr']);
        self::assertNull($this->repo()->findByEmail('boss@example.com'));
    }

    public function testRefusesDuplicateEmail(): void
    {
        $this->repo()->createAdmin('boss@example.com', 'Boss', 'a-strong-password');

        $result = $this->osf(
            ['admin:create', '--email=boss@example.com', '--name=Boss'],
            "another-strong-password\nanother-strong-password\n"
        );

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('already exists', $result['stderr']);
    }

    private function repo(): AdminRepository
    {
        $hasher = new PasswordHasher();
        $db = Database::connect('sqlite:' . $this->dbPath);
        // The CLI runs migrations; ensure the schema exists for direct reads too.
        (new \OpenSendForm\Storage\MigrationRunner($db, dirname(__DIR__, 2) . '/migrations'))->migrate();

        return new AdminRepository($db, $hasher, new RecoveryCodes($hasher));
    }

    /**
     * @param array<int, string> $args
     * @return array{code:int, stdout:string, stderr:string}
     */
    private function osf(array $args, string $stdin): array
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

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
