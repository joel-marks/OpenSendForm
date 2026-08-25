<?php

declare(strict_types=1);

namespace OpenSendForm\Install;

/**
 * The narrow view of the host environment the requirements check depends on.
 *
 * Everything the Requirements check needs to know about the running host is
 * funnelled through this seam so tests can drive every pass/warn/fail branch
 * with a fake, never touching the real PHP build, extensions, filesystem or
 * request. SystemProbe is the production implementation.
 */
interface EnvironmentProbe
{
    /** The running PHP version, e.g. "8.1.27". */
    public function phpVersion(): string;

    /** Whether a named PHP extension is loaded, e.g. "pdo_sqlite". */
    public function hasExtension(string $name): bool;

    /** Whether the given filesystem path exists and is writable. */
    public function isWritable(string $path): bool;

    /** Whether the current request is being served over HTTPS. */
    public function isHttps(): bool;
}
