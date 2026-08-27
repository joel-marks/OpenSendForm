<?php

declare(strict_types=1);

namespace OpenSendForm\Storage;

use RuntimeException;

/**
 * Minimal forward-only migration runner.
 *
 * Migrations are numbered .sql files in a directory (e.g. 001_*.sql).
 * Applied migrations are tracked by their numeric version in the
 * schema_migrations table, which migration 001 is responsible for
 * creating. Running is idempotent: already-applied migrations are
 * skipped on subsequent runs.
 */
final class MigrationRunner
{
    private Database $db;
    private string $migrationsPath;

    public function __construct(Database $db, string $migrationsPath)
    {
        $this->db = $db;
        $this->migrationsPath = rtrim($migrationsPath, '/');
    }

    /**
     * Apply all pending migrations in ascending version order.
     *
     * @return array<int, string> Versions applied during this run.
     */
    public function migrate(): array
    {
        $applied = [];

        foreach ($this->pendingMigrations() as $version => $file) {
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException("Unable to read migration: {$file}");
            }

            // Execute the migration body, then record it. Both statements run
            // inside a transaction so a failure leaves no partial version.
            $pdo = $this->db->pdo();
            $pdo->beginTransaction();
            try {
                $pdo->exec($sql);
                $this->db->execute(
                    'INSERT INTO schema_migrations (version) VALUES (:version)',
                    ['version' => $version]
                );
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            $applied[] = $this->basename($file);
        }

        return $applied;
    }

    /**
     * How many migrations are pending. Cheap: a directory listing plus one
     * SELECT, no schema changes — safe to call on every request.
     */
    public function pendingCount(): int
    {
        return count($this->pendingMigrations());
    }

    /**
     * Versions already recorded as applied.
     *
     * @return array<int, int>
     */
    public function appliedVersions(): array
    {
        if (!$this->schemaMigrationsExists()) {
            return [];
        }

        $rows = $this->db->fetchAll(
            'SELECT version FROM schema_migrations ORDER BY version'
        );

        return array_map(static fn (array $row): int => (int) $row['version'], $rows);
    }

    /**
     * Pending migration files keyed by version, in ascending order.
     *
     * @return array<int, string>
     */
    private function pendingMigrations(): array
    {
        $applied = array_flip($this->appliedVersions());

        $pending = [];
        foreach ($this->discoverMigrations() as $version => $file) {
            if (!isset($applied[$version])) {
                $pending[$version] = $file;
            }
        }

        ksort($pending);

        return $pending;
    }

    /**
     * All migration files on disk, keyed by parsed version number.
     *
     * @return array<int, string>
     */
    private function discoverMigrations(): array
    {
        if (!is_dir($this->migrationsPath)) {
            throw new RuntimeException(
                "Migrations directory not found: {$this->migrationsPath}"
            );
        }

        $files = glob($this->migrationsPath . '/*.sql');
        if ($files === false) {
            return [];
        }

        $migrations = [];
        foreach ($files as $file) {
            $name = $this->basename($file);
            if (!preg_match('/^(\d+)/', $name, $matches)) {
                throw new RuntimeException(
                    "Migration filename must start with a number: {$name}"
                );
            }

            $version = (int) $matches[1];
            if (isset($migrations[$version])) {
                throw new RuntimeException(
                    "Duplicate migration version {$version}: {$name}"
                );
            }

            $migrations[$version] = $file;
        }

        ksort($migrations);

        return $migrations;
    }

    private function schemaMigrationsExists(): bool
    {
        $driver = $this->db->driver();

        if ($driver === 'sqlite') {
            $row = $this->db->fetchOne(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'schema_migrations'"
            );

            return $row !== null;
        }

        // mysql and other drivers: rely on information_schema.
        $row = $this->db->fetchOne(
            'SELECT table_name FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_name = :name',
            ['name' => 'schema_migrations']
        );

        return $row !== null;
    }

    private function basename(string $path): string
    {
        return basename($path);
    }
}
