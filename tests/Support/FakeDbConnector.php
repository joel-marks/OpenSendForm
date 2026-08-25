<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Support;

use OpenSendForm\Install\DbConnector;
use OpenSendForm\Storage\Database;
use RuntimeException;

/**
 * A DbConnector double for exercising the installer's database step —
 * especially the MySQL live-connection test — without a real MySQL server.
 *
 * By default connect() returns a fresh in-memory SQLite Database, standing in
 * for a successful connection so migrations and admin creation can run against
 * it. Call failWith() to make connect() throw, simulating a refused/misconfig
 * connection. The last DSN and credentials seen are recorded for assertions.
 */
final class FakeDbConnector implements DbConnector
{
    public ?string $lastDsn = null;
    public ?string $lastUser = null;
    public ?string $lastPass = null;
    public int $calls = 0;

    private ?string $failMessage = null;
    private ?Database $shared;

    public function __construct(?Database $shared = null)
    {
        // A shared in-memory database persists across the wizard's repeated
        // connect() calls (DB step, then admin step) so the migrated schema and
        // the created admin are visible on the next connect within one test.
        $this->shared = $shared;
    }

    public function failWith(string $message): void
    {
        $this->failMessage = $message;
    }

    public function connect(string $dsn, ?string $username, ?string $password): Database
    {
        $this->calls++;
        $this->lastDsn = $dsn;
        $this->lastUser = $username;
        $this->lastPass = $password;

        if ($this->failMessage !== null) {
            throw new RuntimeException($this->failMessage);
        }

        if ($this->shared !== null) {
            return $this->shared;
        }

        return Database::connect('sqlite::memory:');
    }
}
