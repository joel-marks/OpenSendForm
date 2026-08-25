<?php

declare(strict_types=1);

namespace OpenSendForm\Tests;

use OpenSendForm\Config;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the Increment 6a config refactor: fromFile(), the merged
 * load() factory (defaults < file < environment), the APP_SECRET generator and
 * the DB credential accessors.
 */
final class ConfigMergeTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/osf_cfg_' . bin2hex(random_bytes(6)) . '.php';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    /**
     * @param array<string, string> $values
     */
    private function writeConfig(array $values): void
    {
        $export = '';
        foreach ($values as $k => $v) {
            $export .= '    ' . var_export($k, true) . ' => ' . var_export($v, true) . ",\n";
        }
        file_put_contents($this->file, "<?php\nreturn [\n{$export}];\n");
    }

    public function testFromFileReadsValuesOverDefaults(): void
    {
        $this->writeConfig([
            'APP_ENV' => 'production',
            'DB_DSN'  => 'sqlite:/data/app.sqlite',
            'DB_USER' => 'osf_user',
        ]);

        $config = Config::fromFile($this->file);

        self::assertSame('production', $config->appEnv());
        self::assertSame('sqlite:/data/app.sqlite', $config->dbDsn());
        self::assertSame('osf_user', $config->dbUser());
        // Unspecified keys keep their shipped defaults.
        self::assertSame('localhost', $config->smtpHost());
    }

    public function testLoadAppliesFileThenEnvironmentOverrides(): void
    {
        $this->writeConfig([
            'DB_DSN'      => 'sqlite:/from/file.sqlite',
            'SMTP_HOST'   => 'file-host',
            'MAIL_ENABLED' => '0',
        ]);

        // Environment WINS over the file for SMTP_HOST; DB_DSN comes from the
        // file (no env override); MAIL_ENABLED stays the file's 0.
        $config = Config::load($this->file, [
            'SMTP_HOST' => 'env-host',
        ]);

        self::assertSame('env-host', $config->smtpHost(), 'env overrides file');
        self::assertSame('sqlite:/from/file.sqlite', $config->dbDsn(), 'file value kept when no env');
        self::assertFalse($config->mailEnabled(), 'file MAIL_ENABLED=0 preserved');
    }

    public function testLoadWithoutFileIsPureEnvironment(): void
    {
        // No file present → identical to fromEnvironment (devcontainer path).
        $config = Config::load(null, ['SMTP_HOST' => 'mailpit', 'SMTP_PORT' => '1025']);

        self::assertSame('mailpit', $config->smtpHost());
        self::assertSame(1025, $config->smtpPort());
        self::assertStringStartsWith('sqlite:', $config->dbDsn());
    }

    public function testLoadIgnoresMissingFilePath(): void
    {
        $config = Config::load('/no/such/config.php', []);

        self::assertSame('localhost', $config->smtpHost());
    }

    public function testBlankEnvironmentDoesNotClobberFileValue(): void
    {
        $this->writeConfig(['SMTP_HOST' => 'file-host']);

        // An empty env var must not override a file-supplied value.
        $config = Config::load($this->file, ['SMTP_HOST' => '']);

        self::assertSame('file-host', $config->smtpHost());
    }

    public function testGenerateSecretIs64HexChars(): void
    {
        $secret = Config::generateSecret();

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $secret);
        self::assertNotSame(Config::generateSecret(), $secret, 'each secret is unique');
    }

    public function testDbCredentialsNullWhenEmpty(): void
    {
        $config = Config::fromEnvironment([]);

        self::assertNull($config->dbUser());
        self::assertNull($config->dbPass());
    }

    public function testDbCredentialsReturnedWhenSet(): void
    {
        $config = Config::fromEnvironment(['DB_USER' => 'osf', 'DB_PASS' => 'pw']);

        self::assertSame('osf', $config->dbUser());
        self::assertSame('pw', $config->dbPass());
    }
}
