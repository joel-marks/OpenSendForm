<?php

declare(strict_types=1);

namespace OpenSendForm\Install;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The production EnvironmentProbe: answers requirement questions against the
 * real PHP build, loaded extensions, filesystem and the current request.
 *
 * HTTPS detection reads the request (proxy-friendly: honours the standard
 * X-Forwarded-Proto shared-hosting reverse proxies set) rather than PHP's
 * $_SERVER directly, keeping it testable and consistent with how the app sees
 * the request.
 */
final class SystemProbe implements EnvironmentProbe
{
    public function __construct(private ?ServerRequestInterface $request = null)
    {
    }

    public function phpVersion(): string
    {
        return PHP_VERSION;
    }

    public function hasExtension(string $name): bool
    {
        return extension_loaded($name);
    }

    public function isWritable(string $path): bool
    {
        return is_dir($path) && is_writable($path);
    }

    public function isHttps(): bool
    {
        $request = $this->request;
        if ($request === null) {
            return false;
        }

        $forwarded = strtolower(trim($request->getHeaderLine('X-Forwarded-Proto')));
        if ($forwarded !== '') {
            return $forwarded === 'https';
        }

        $params = $request->getServerParams();
        $https = strtolower((string) ($params['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }

        return $request->getUri()->getScheme() === 'https';
    }
}
