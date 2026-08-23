<?php

declare(strict_types=1);

namespace OpenSendForm;

/**
 * Application configuration.
 *
 * Loads hard-coded defaults and allows a small, explicit set of
 * environment variables to override them. No secrets live in code;
 * anything sensitive is supplied through the environment at runtime.
 */
final class Config
{
    /** @var array<string,string> */
    private array $values;

    /**
     * @param array<string,string> $values Fully-resolved configuration values.
     */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    /**
     * Build configuration from defaults, overridden by the given environment.
     *
     * @param array<string,string|false|null> $env Environment map; defaults to
     *                                              the process environment.
     */
    public static function fromEnvironment(?array $env = null): self
    {
        $env ??= getenv();

        $defaults = self::defaults();
        $values = $defaults;

        foreach (array_keys($defaults) as $key) {
            if (array_key_exists($key, $env)) {
                $value = $env[$key];
                if ($value !== false && $value !== null && $value !== '') {
                    $values[$key] = (string) $value;
                }
            }
        }

        return new self($values);
    }

    /**
     * Default configuration values.
     *
     * @return array<string,string>
     */
    public static function defaults(): array
    {
        return [
            'APP_ENV'   => 'production',
            'SMTP_HOST' => 'localhost',
            'SMTP_PORT' => '25',
            'DB_DSN'    => 'sqlite:' . self::defaultDatabasePath(),
        ];
    }

    /**
     * Absolute path to the default SQLite database file.
     */
    public static function defaultDatabasePath(): string
    {
        return dirname(__DIR__) . '/var/data/opensendform.sqlite';
    }

    public function get(string $key): string
    {
        if (!array_key_exists($key, $this->values)) {
            throw new \InvalidArgumentException("Unknown config key: {$key}");
        }

        return $this->values[$key];
    }

    public function appEnv(): string
    {
        return $this->get('APP_ENV');
    }

    public function smtpHost(): string
    {
        return $this->get('SMTP_HOST');
    }

    public function smtpPort(): int
    {
        return (int) $this->get('SMTP_PORT');
    }

    public function dbDsn(): string
    {
        return $this->get('DB_DSN');
    }

    /**
     * @return array<string,string>
     */
    public function all(): array
    {
        return $this->values;
    }
}
