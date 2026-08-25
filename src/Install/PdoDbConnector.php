<?php

declare(strict_types=1);

namespace OpenSendForm\Install;

use OpenSendForm\Storage\Database;

/**
 * The production DbConnector: opens a real PDO-backed Database. A failure
 * surfaces as the underlying PDOException, which the installer catches and
 * renders as a plain-language message (never the raw driver text).
 */
final class PdoDbConnector implements DbConnector
{
    public function connect(string $dsn, ?string $username, ?string $password): Database
    {
        return Database::connect($dsn, $username, $password);
    }
}
