<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Install;

use OpenSendForm\Config;
use OpenSendForm\Install\ConfigWriter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The atomic config write-back used by the mail wizard. Exercised against a
 * throwaway temp directory; asserts the merge preserves untouched keys, that
 * Config::load() reads the result back, and that environment overrides still
 * win over a freshly written file (the precedence contract is unchanged).
 */
final class ConfigWriterTest extends TestCase
{
    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/osf-cfgwriter-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0775, true);
        $this->path = $this->dir . '/config.php';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
        if (is_dir($this->dir)) {
            @rmdir($this->dir);
        }
    }

    private function seed(array $values): void
    {
        $lines = '';
        foreach ($values as $k => $v) {
            $lines .= '    ' . var_export($k, true) . ' => ' . var_export((string) $v, true) . ",\n";
        }
        file_put_contents($this->path, "<?php\nreturn [\n{$lines}];\n");
    }

    public function testCurrentFileValuesReadsStoredArray(): void
    {
        $this->seed(['APP_SECRET' => 'abc', 'SMTP_HOST' => 'mail.example.com']);

        $writer = new ConfigWriter($this->path);
        $values = $writer->currentFileValues();

        self::assertSame('abc', $values['APP_SECRET']);
        self::assertSame('mail.example.com', $values['SMTP_HOST']);
    }

    public function testCurrentFileValuesEmptyWhenNoFile(): void
    {
        self::assertSame([], (new ConfigWriter($this->path))->currentFileValues());
        self::assertNull((new ConfigWriter($this->path))->fileValue('SMTP_HOST'));
    }

    public function testSaveMergesAndPreservesUntouchedKeys(): void
    {
        $this->seed(['APP_SECRET' => 'keep-me', 'DB_DSN' => 'sqlite:x', 'MAIL_ENABLED' => '0']);

        $writer = new ConfigWriter($this->path);
        $writer->save(['SMTP_HOST' => 'smtp.example.com', 'MAIL_ENABLED' => '1']);

        $values = (new ConfigWriter($this->path))->currentFileValues();
        // Changed keys applied.
        self::assertSame('smtp.example.com', $values['SMTP_HOST']);
        self::assertSame('1', $values['MAIL_ENABLED']);
        // Untouched keys preserved.
        self::assertSame('keep-me', $values['APP_SECRET']);
        self::assertSame('sqlite:x', $values['DB_DSN']);
    }

    public function testSaveWritesAValidPhpFileConfigCanLoad(): void
    {
        $this->seed(['APP_ENV' => 'production', 'APP_SECRET' => str_repeat('a', 64)]);

        (new ConfigWriter($this->path))->save([
            'SMTP_HOST'         => 'mail.example.com',
            'SMTP_PORT'         => '587',
            'MAIL_FROM_ADDRESS' => 'hello@example.com',
            'MAIL_ENABLED'      => '1',
        ]);

        $config = Config::load($this->path, []);
        self::assertSame('mail.example.com', $config->smtpHost());
        self::assertSame(587, $config->smtpPort());
        self::assertSame('hello@example.com', $config->mailFromAddress());
        self::assertTrue($config->mailEnabled());
    }

    public function testKeepExistingPasswordViaFileValue(): void
    {
        // Simulate the wizard's "blank keeps the stored password" rule: the
        // controller reads fileValue('SMTP_PASS') and simply doesn't overwrite it.
        $this->seed(['SMTP_PASS' => 's3cr3t', 'SMTP_HOST' => 'old']);

        $writer = new ConfigWriter($this->path);
        self::assertSame('s3cr3t', $writer->fileValue('SMTP_PASS'));

        // Save without SMTP_PASS in the change set keeps it.
        $writer->save(['SMTP_HOST' => 'new']);
        $values = (new ConfigWriter($this->path))->currentFileValues();
        self::assertSame('s3cr3t', $values['SMTP_PASS']);
        self::assertSame('new', $values['SMTP_HOST']);
    }

    public function testEnvironmentStillOverridesAFreshlyWrittenFile(): void
    {
        $this->seed(['APP_SECRET' => 'x']);
        (new ConfigWriter($this->path))->save(['SMTP_HOST' => 'file-host']);

        // Precedence: defaults < file < environment. Env wins.
        $config = Config::load($this->path, ['SMTP_HOST' => 'env-host']);
        self::assertSame('env-host', $config->smtpHost());
    }

    public function testSaveThrowsWhenDirectoryUnwritable(): void
    {
        $this->expectException(RuntimeException::class);
        (new ConfigWriter('/nonexistent-dir-osf/config.php'))->save(['SMTP_HOST' => 'x']);
    }
}
