<?php

declare(strict_types=1);

namespace OpenSendForm\Install;

use OpenSendForm\Storage\Database;

/**
 * Opens a database connection for the installer's chosen settings.
 *
 * This is the seam that keeps the live MySQL connection test — the one part of
 * the wizard that would otherwise touch a real network service — injectable.
 * PdoDbConnector is the production implementation; tests supply a fake that
 * returns an in-memory SQLite database on "success" or throws to simulate a
 * refused connection.
 *
 * connect() MUST throw on any failure (bad host, wrong credentials, unknown
 * database); the installer translates the throw into a friendly message.
 */
interface DbConnector
{
    public function connect(string $dsn, ?string $username, ?string $password): Database;
}
