<?php

declare(strict_types=1);

namespace OpenSendForm\Storage;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper.
 *
 * - Throws exceptions on error (PDO::ERRMODE_EXCEPTION).
 * - Only exposes prepared-statement execution; callers never build SQL
 *   with interpolated values.
 * - Supports sqlite and mysql DSNs.
 */
final class Database
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $this->pdo = $pdo;
    }

    /**
     * Connect using a DSN and optional credentials.
     *
     * For a file-backed sqlite DSN the containing directory is created if
     * it does not yet exist. An in-memory sqlite DSN is left untouched.
     */
    public static function connect(
        string $dsn,
        ?string $username = null,
        ?string $password = null
    ): self {
        self::ensureSqliteDirectory($dsn);

        $pdo = new PDO($dsn, $username, $password);

        return new self($pdo);
    }

    /**
     * Run a prepared statement and return it after execution.
     *
     * @param array<string|int, mixed> $params
     */
    public function execute(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /**
     * Fetch all rows for a prepared query.
     *
     * @param array<string|int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->execute($sql, $params)->fetchAll();
    }

    /**
     * Fetch a single row, or null if none.
     *
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->execute($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Identify the PDO driver in use (e.g. "sqlite", "mysql").
     */
    public function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Create the parent directory for a file-backed sqlite database.
     */
    private static function ensureSqliteDirectory(string $dsn): void
    {
        if (!str_starts_with($dsn, 'sqlite:')) {
            return;
        }

        $path = substr($dsn, strlen('sqlite:'));

        // In-memory or anonymous temporary databases need no directory.
        if ($path === '' || $path === ':memory:') {
            return;
        }

        $directory = dirname($path);
        if ($directory !== '' && !is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }
}
