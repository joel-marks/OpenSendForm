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

        return self::finalise($values);
    }

    /**
     * Build configuration from an explicit, fully-trusted override map.
     *
     * Unlike fromEnvironment(), an explicitly supplied empty string IS
     * honoured (it does not fall back to the default). This is the seam
     * tests and programmatic callers use to express states the environment
     * parser deliberately protects against — e.g. an empty SMTP_HOST to
     * request storage-only operation.
     *
     * @param array<string,string> $overrides
     */
    public static function fromValues(array $overrides): self
    {
        $values = self::defaults();

        foreach ($overrides as $key => $value) {
            if (array_key_exists($key, $values)) {
                $values[$key] = (string) $value;
            }
        }

        return self::finalise($values);
    }

    /**
     * Apply cross-cutting post-processing and construct.
     *
     * @param array<string,string> $values
     */
    private static function finalise(array $values): self
    {
        // APP_SECRET keys the submit-token HMAC and must be a real secret in
        // production. We ship no secret in code; the browser installer will
        // generate a strong one and write it to the environment on install.
        // The only automatic value is a throwaway used in local development
        // so the endpoint works out of the box; every other environment
        // (including 'production') gets an empty secret until one is supplied.
        if ($values['APP_SECRET'] === '' && $values['APP_ENV'] === 'dev') {
            $values['APP_SECRET'] = 'dev-secret-do-not-use';
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
            'APP_ENV'                => 'production',
            // Primary delivery switch: mail is attempted only when this is
            // truthy AND SMTP_HOST is non-empty (the host check remains a
            // guard, not the primary switch).
            'MAIL_ENABLED'           => '1',
            'SMTP_HOST'              => 'localhost',
            'SMTP_PORT'              => '25',
            'DB_DSN'                 => 'sqlite:' . self::defaultDatabasePath(),

            // Submit-token signing secret. Empty by default; see the
            // dev-only fallback applied in fromEnvironment().
            'APP_SECRET'             => '',

            // Request/payload caps (bytes / counts).
            'MAX_BODY_BYTES'         => '65536',
            'MAX_FIELDS'             => '50',
            'MAX_FIELD_NAME_BYTES'   => '100',
            'MAX_FIELD_VALUE_BYTES'  => '10240',

            // Abuse-filter timing (seconds).
            'MIN_SUBMIT_SECONDS'     => '3',
            'TOKEN_MAX_AGE_SECONDS'  => '3600',

            // Fixed-window rate limits.
            'RATE_IP_PER_MINUTE'     => '5',
            'RATE_FORM_PER_HOUR'     => '60',

            // SMTP authentication (empty = no auth, as with Mailpit in dev).
            'SMTP_USER'              => '',
            'SMTP_PASS'              => '',
            // Transport security: 'none' (Mailpit/dev), 'starttls' or 'smtps'.
            'SMTP_ENCRYPTION'        => 'none',

            // Envelope/header identity. From: is ALWAYS this address; the
            // submitter never controls it.
            'MAIL_FROM_ADDRESS'      => 'noreply@localhost',
            'MAIL_FROM_NAME'         => 'OpenSendForm',

            // Retry policy. A submission is attempted at most MAIL_MAX_ATTEMPTS
            // times; the backoff list gives the wait (minutes) after each
            // failure, the last value repeating if failures exceed the list.
            'MAIL_MAX_ATTEMPTS'      => '5',
            'MAIL_RETRY_BACKOFF_MINUTES' => '1,5,30,120',
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

    /**
     * Primary mail delivery switch. Delivery is attempted only when this is
     * true AND smtpHost() is non-empty; the host stays a secondary guard.
     */
    public function mailEnabled(): bool
    {
        return in_array(strtolower(trim($this->get('MAIL_ENABLED'))), ['1', 'true', 'yes', 'on'], true);
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

    public function appSecret(): string
    {
        return $this->get('APP_SECRET');
    }

    public function maxBodyBytes(): int
    {
        return (int) $this->get('MAX_BODY_BYTES');
    }

    public function maxFields(): int
    {
        return (int) $this->get('MAX_FIELDS');
    }

    public function maxFieldNameBytes(): int
    {
        return (int) $this->get('MAX_FIELD_NAME_BYTES');
    }

    public function maxFieldValueBytes(): int
    {
        return (int) $this->get('MAX_FIELD_VALUE_BYTES');
    }

    public function minSubmitSeconds(): int
    {
        return (int) $this->get('MIN_SUBMIT_SECONDS');
    }

    public function tokenMaxAgeSeconds(): int
    {
        return (int) $this->get('TOKEN_MAX_AGE_SECONDS');
    }

    public function rateIpPerMinute(): int
    {
        return (int) $this->get('RATE_IP_PER_MINUTE');
    }

    public function rateFormPerHour(): int
    {
        return (int) $this->get('RATE_FORM_PER_HOUR');
    }

    public function smtpUser(): string
    {
        return $this->get('SMTP_USER');
    }

    public function smtpPass(): string
    {
        return $this->get('SMTP_PASS');
    }

    /**
     * SMTP transport security: one of 'none', 'starttls', 'smtps'.
     * Anything unrecognised is treated as 'none'.
     */
    public function smtpEncryption(): string
    {
        $value = strtolower(trim($this->get('SMTP_ENCRYPTION')));

        return in_array($value, ['none', 'starttls', 'smtps'], true) ? $value : 'none';
    }

    public function mailFromAddress(): string
    {
        return $this->get('MAIL_FROM_ADDRESS');
    }

    public function mailFromName(): string
    {
        return $this->get('MAIL_FROM_NAME');
    }

    public function mailMaxAttempts(): int
    {
        return max(1, (int) $this->get('MAIL_MAX_ATTEMPTS'));
    }

    /**
     * Retry backoff schedule in minutes, parsed from the comma list. Only
     * positive integers are kept; an empty or malformed list falls back to
     * a single one-minute wait so retry can never schedule a zero/negative
     * delay.
     *
     * @return array<int,int> One or more positive minute values.
     */
    public function mailRetryBackoffMinutes(): array
    {
        $parts = explode(',', $this->get('MAIL_RETRY_BACKOFF_MINUTES'));

        $minutes = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part) && (int) $part > 0) {
                $minutes[] = (int) $part;
            }
        }

        return $minutes === [] ? [1] : $minutes;
    }

    /**
     * @return array<string,string>
     */
    public function all(): array
    {
        return $this->values;
    }
}
