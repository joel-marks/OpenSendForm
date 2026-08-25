<?php

declare(strict_types=1);

namespace OpenSendForm\Install;

/**
 * The filesystem locations the installer reads and writes.
 *
 * Bundled into one value object so every collaborator (the state middleware,
 * the installer service, the CLI) agrees on where the config file, the lock
 * file, the writable var/ tree and the migrations live — and so tests can
 * point the whole installer at a temporary directory instead of the real
 * project. "Installed" is defined here, in one place: BOTH the config file and
 * the lock file must exist.
 */
final class Paths
{
    public function __construct(
        public readonly string $configPath,
        public readonly string $lockPath,
        public readonly string $varDir,
        public readonly string $dataDir,
        public readonly string $migrationsPath
    ) {
    }

    /**
     * The real project layout: var/config.php, var/install.lock, var/,
     * var/data/ and migrations/ under the project root.
     *
     * The OSF_BASE_DIR environment variable relocates the writable var/ tree
     * (some shared hosts prefer it outside the document root); migrations still
     * ship with the code. When unset, everything sits under the project root.
     */
    public static function production(): self
    {
        $root = dirname(__DIR__, 2);
        $migrations = $root . '/migrations';

        $base = getenv('OSF_BASE_DIR');
        if (is_string($base) && $base !== '') {
            return self::underBase($base, $migrations);
        }

        return new self(
            $root . '/var/config.php',
            $root . '/var/install.lock',
            $root . '/var',
            $root . '/var/data',
            $migrations
        );
    }

    /**
     * A layout rooted at an arbitrary base directory — the seam tests use to
     * run the whole wizard against a throwaway temp dir. Migrations still come
     * from the real project so the schema under test is the shipped one.
     */
    public static function underBase(string $baseDir, ?string $migrationsPath = null): self
    {
        $baseDir = rtrim($baseDir, '/');

        return new self(
            $baseDir . '/var/config.php',
            $baseDir . '/var/install.lock',
            $baseDir . '/var',
            $baseDir . '/var/data',
            $migrationsPath ?? (dirname(__DIR__, 2) . '/migrations')
        );
    }

    /**
     * Installed iff BOTH the config file and the lock file are present.
     * Deleting either (via the host's file manager) reopens the installer.
     */
    public function isInstalled(): bool
    {
        return is_file($this->configPath) && is_file($this->lockPath);
    }
}
