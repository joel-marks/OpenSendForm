<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Install;

use OpenSendForm\Install\Paths;
use OpenSendForm\Install\Requirements;
use OpenSendForm\Tests\Support\FakeProbe;
use PHPUnit\Framework\TestCase;

/**
 * Drives every pass/warn/fail branch of the environment requirements matrix
 * with a fake probe, asserting the status and that a non-pass row carries
 * plain-language remedy text. No real host, filesystem or request is touched.
 */
final class RequirementsTest extends TestCase
{
    private Paths $paths;

    protected function setUp(): void
    {
        $this->paths = Paths::underBase('/tmp/osf-fake-base');
    }

    public function testHealthyHostHasNoFailures(): void
    {
        $req = new Requirements(new FakeProbe(), $this->paths);

        self::assertFalse($req->hasFailures());
        foreach ($req->checks() as $check) {
            self::assertSame(Requirements::PASS, $check['status'], $check['key'] . ' should pass');
            self::assertSame('', $check['remedy'], 'a passing check has no remedy');
        }
    }

    public function testOldPhpFails(): void
    {
        $probe = new FakeProbe();
        $probe->phpVersion = '8.0.30';
        $req = new Requirements($probe, $this->paths);

        $check = $this->check($req, 'php_version');
        self::assertSame(Requirements::FAIL, $check['status']);
        self::assertStringContainsString('PHP 8.1', $check['remedy']);
        self::assertTrue($req->hasFailures());
    }

    public function testSqliteAbsentButMysqlPresentIsWarn(): void
    {
        $probe = new FakeProbe();
        $probe->extensions['pdo_sqlite'] = false;
        $probe->extensions['pdo_mysql'] = true;
        $req = new Requirements($probe, $this->paths);

        $sqlite = $this->check($req, 'pdo_sqlite');
        self::assertSame(Requirements::WARN, $sqlite['status']);
        self::assertNotSame('', $sqlite['remedy']);
        // A warn does not block install on its own.
        self::assertFalse($req->hasFailures());
    }

    public function testBothDatabaseDriversAbsentFailsOnSqlite(): void
    {
        $probe = new FakeProbe();
        $probe->extensions['pdo_sqlite'] = false;
        $probe->extensions['pdo_mysql'] = false;
        $req = new Requirements($probe, $this->paths);

        self::assertSame(Requirements::FAIL, $this->check($req, 'pdo_sqlite')['status']);
        self::assertSame(Requirements::WARN, $this->check($req, 'pdo_mysql')['status']);
        self::assertTrue($req->hasFailures());
    }

    public function testMysqlAbsentWithSqlitePresentIsWarn(): void
    {
        $probe = new FakeProbe();
        $probe->extensions['pdo_mysql'] = false;
        $req = new Requirements($probe, $this->paths);

        $mysql = $this->check($req, 'pdo_mysql');
        self::assertSame(Requirements::WARN, $mysql['status']);
        self::assertNotSame('', $mysql['remedy']);
        self::assertSame(Requirements::PASS, $this->check($req, 'pdo_sqlite')['status']);
        self::assertFalse($req->hasFailures());
    }

    public function testOpensslAbsentFails(): void
    {
        $probe = new FakeProbe();
        $probe->extensions['openssl'] = false;
        $req = new Requirements($probe, $this->paths);

        $check = $this->check($req, 'openssl');
        self::assertSame(Requirements::FAIL, $check['status']);
        self::assertNotSame('', $check['remedy']);
        self::assertTrue($req->hasFailures());
    }

    public function testCurlAbsentIsWarnMentioningTurnstile(): void
    {
        $probe = new FakeProbe();
        $probe->extensions['curl'] = false;
        $req = new Requirements($probe, $this->paths);

        $check = $this->check($req, 'curl');
        self::assertSame(Requirements::WARN, $check['status']);
        self::assertStringContainsString('Turnstile', $check['remedy']);
        self::assertFalse($req->hasFailures());
    }

    public function testUnwritableVarFolderFails(): void
    {
        $probe = new FakeProbe();
        $probe->writable[$this->paths->varDir] = false;
        $req = new Requirements($probe, $this->paths);

        $check = $this->check($req, 'writable_var');
        self::assertSame(Requirements::FAIL, $check['status']);
        self::assertNotSame('', $check['remedy']);
        self::assertTrue($req->hasFailures());
    }

    public function testUnwritableDataFolderFails(): void
    {
        $probe = new FakeProbe();
        $probe->writable[$this->paths->dataDir] = false;
        $req = new Requirements($probe, $this->paths);

        $check = $this->check($req, 'writable_var_data');
        self::assertSame(Requirements::FAIL, $check['status']);
        self::assertTrue($req->hasFailures());
    }

    public function testNoHttpsIsWarnOnly(): void
    {
        $probe = new FakeProbe();
        $probe->https = false;
        $req = new Requirements($probe, $this->paths);

        $check = $this->check($req, 'https');
        self::assertSame(Requirements::WARN, $check['status']);
        self::assertNotSame('', $check['remedy']);
        self::assertFalse($req->hasFailures());
    }

    /**
     * @return array{key:string, label:string, status:string, remedy:string}
     */
    private function check(Requirements $req, string $key): array
    {
        foreach ($req->checks() as $check) {
            if ($check['key'] === $key) {
                return $check;
            }
        }

        self::fail("No requirement check with key '{$key}'.");
    }
}
