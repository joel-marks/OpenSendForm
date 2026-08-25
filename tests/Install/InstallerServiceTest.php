<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Install;

use OpenSendForm\Auth\AdminRepository;
use OpenSendForm\Auth\PasswordHasher;
use OpenSendForm\Auth\RecoveryCodes;
use OpenSendForm\Config;
use OpenSendForm\Install\InstallerException;
use OpenSendForm\Install\InstallerService;
use OpenSendForm\Install\Paths;
use OpenSendForm\Storage\Database;
use OpenSendForm\Tests\Support\FakeDbConnector;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the installer engine against a throwaway temp directory
 * and a fake DB connector — no real MySQL, no touching the project's own var/.
 * Exercises the MySQL live-test seam (success and friendly failure), the
 * written config/lock contents, and commit atomicity.
 */
final class InstallerServiceTest extends TestCase
{
    private string $base;
    private Paths $paths;
    private PasswordHasher $hasher;
    private RecoveryCodes $recovery;
    private string $migrations;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/osf_inst_' . bin2hex(random_bytes(6));
        mkdir($this->base . '/var/data', 0775, true);

        $this->migrations = dirname(__DIR__, 2) . '/migrations';
        $this->paths = Paths::underBase($this->base, $this->migrations);
        $this->hasher = new PasswordHasher(PASSWORD_BCRYPT);
        $this->recovery = new RecoveryCodes($this->hasher);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->base);
    }

    // --- MySQL path (fake connector) --------------------------------------

    public function testMysqlHappyPathConnectsMigratesAndCreatesAdmin(): void
    {
        $shared = Database::connect('sqlite::memory:');
        $connector = new FakeDbConnector($shared);
        $svc = new InstallerService($this->paths, $connector, $this->hasher, $this->recovery);

        $dbConfig = $svc->prepareDatabase([
            'db_driver' => 'mysql',
            'db_host'   => 'db.example.com',
            'db_port'   => '3306',
            'db_name'   => 'osf',
            'db_user'   => 'osf_user',
            'db_pass'   => 'sekret',
        ]);

        self::assertSame('mysql', $dbConfig['driver']);
        self::assertStringContainsString('host=db.example.com', $dbConfig['dsn']);
        self::assertStringContainsString('dbname=osf', $dbConfig['dsn']);
        self::assertSame('osf_user', $dbConfig['user']);

        $db = $svc->connect($dbConfig);
        self::assertSame('osf_user', $connector->lastUser);
        self::assertSame('sekret', $connector->lastPass);

        $svc->migrate($db);
        $svc->createAdmin($db, 'boss@example.com', 'The Boss', 'a-strong-password-1', 'a-strong-password-1');

        $repo = new AdminRepository($shared, $this->hasher, $this->recovery);
        $admin = $repo->findByEmail('boss@example.com');
        self::assertNotNull($admin);
        self::assertTrue($this->hasher->verify('a-strong-password-1', $admin['password_hash']));
    }

    public function testMysqlConnectionFailureIsFriendlyAndHidesDriverDetail(): void
    {
        $connector = new FakeDbConnector();
        $connector->failWith('SQLSTATE[HY000] [2002] Connection refused');
        $svc = new InstallerService($this->paths, $connector, $this->hasher, $this->recovery);

        $dbConfig = $svc->prepareDatabase([
            'db_driver' => 'mysql',
            'db_host'   => 'nope.example.com',
            'db_name'   => 'osf',
            'db_user'   => 'osf_user',
            'db_pass'   => 'sekret',
        ]);

        try {
            $svc->connect($dbConfig);
            self::fail('Expected an InstallerException.');
        } catch (InstallerException $e) {
            self::assertStringContainsString('Could not connect to the MySQL database', $e->getMessage());
            self::assertStringNotContainsString('SQLSTATE', $e->getMessage());
        }
    }

    public function testMysqlMissingFieldsRejected(): void
    {
        $svc = new InstallerService($this->paths, new FakeDbConnector(), $this->hasher, $this->recovery);

        $this->expectException(InstallerException::class);
        $svc->prepareDatabase(['db_driver' => 'mysql', 'db_host' => '', 'db_name' => 'osf', 'db_user' => 'u']);
    }

    // --- Admin validation -------------------------------------------------

    public function testCreateAdminRejectsShortPassword(): void
    {
        $svc = new InstallerService($this->paths, new FakeDbConnector(), $this->hasher, $this->recovery);
        $db = Database::connect('sqlite::memory:');
        $svc->migrate($db);

        $this->expectException(InstallerException::class);
        $svc->createAdmin($db, 'a@example.com', 'A', 'short', 'short');
    }

    public function testCreateAdminRejectsMismatchedPasswords(): void
    {
        $svc = new InstallerService($this->paths, new FakeDbConnector(), $this->hasher, $this->recovery);
        $db = Database::connect('sqlite::memory:');
        $svc->migrate($db);

        $this->expectException(InstallerException::class);
        $svc->createAdmin($db, 'a@example.com', 'A', 'a-strong-password-1', 'a-strong-password-2');
    }

    // --- Commit: config + lock contents -----------------------------------

    public function testCommitWritesConfigAndLockWithExpectedContents(): void
    {
        $svc = new InstallerService($this->paths, new FakeDbConnector(), $this->hasher, $this->recovery);
        $dbConfig = $svc->prepareDatabase(['db_driver' => 'sqlite']);

        $svc->commit($dbConfig);

        self::assertTrue($this->paths->isInstalled());
        self::assertFileExists($this->paths->configPath);
        self::assertFileExists($this->paths->lockPath);

        $loaded = Config::fromFile($this->paths->configPath);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $loaded->appSecret(), 'secret is 64 hex chars');
        self::assertFalse($loaded->mailEnabled(), 'MAIL_ENABLED written as 0');
        self::assertSame('production', $loaded->appEnv());
        self::assertStringStartsWith('sqlite:', $loaded->dbDsn());

        $lock = json_decode((string) file_get_contents($this->paths->lockPath), true);
        self::assertIsArray($lock);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $lock['installed_at']);
        self::assertSame(\OpenSendForm\Version::STRING, $lock['version']);
    }

    public function testTwoInstallsGetDifferentSecrets(): void
    {
        $svc = new InstallerService($this->paths, new FakeDbConnector(), $this->hasher, $this->recovery);
        $svc->commit($svc->prepareDatabase(['db_driver' => 'sqlite']));
        $first = Config::fromFile($this->paths->configPath)->appSecret();

        // Overwrite with a second commit; the generated secret must differ.
        $svc->commit($svc->prepareDatabase(['db_driver' => 'sqlite']));
        $second = Config::fromFile($this->paths->configPath)->appSecret();

        self::assertNotSame($first, $second);
    }

    // --- Commit atomicity -------------------------------------------------

    public function testCommitLeavesNoPartialStateWhenLockWriteFails(): void
    {
        // A file where the lock's parent directory should be: writing the lock
        // is impossible, so commit must roll back the already-written config.
        $blocker = $this->base . '/blocker';
        file_put_contents($blocker, 'x');

        $badPaths = new Paths(
            $this->base . '/var/config.php',
            $blocker . '/install.lock',      // parent is a file → unwritable
            $this->base . '/var',
            $this->base . '/var/data',
            $this->migrations
        );

        $svc = new InstallerService($badPaths, new FakeDbConnector(), $this->hasher, $this->recovery);
        $dbConfig = $svc->prepareDatabase(['db_driver' => 'sqlite']);

        try {
            $svc->commit($dbConfig);
            self::fail('Expected commit to fail on the lock write.');
        } catch (InstallerException $e) {
            // expected
        }

        self::assertFileDoesNotExist($this->base . '/var/config.php', 'config rolled back');
        self::assertFalse($badPaths->isInstalled());
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
