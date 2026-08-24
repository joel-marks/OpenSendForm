<?php

declare(strict_types=1);

namespace OpenSendForm\Http;

/**
 * Resolves and matches the origin of an incoming request.
 *
 * The browser's Origin header is authoritative; when it is absent we fall
 * back to deriving an origin from the Referer URL. The resolved origin is
 * normalised to the same scheme + host + optional port shape that
 * FormRepository stores, so a plain string comparison against a form's
 * allowlist is exact.
 */
final class OriginMatcher
{
    /**
     * Resolve the request origin from an Origin header, falling back to a
     * Referer URL. Returns a normalised origin, or null if neither yields a
     * usable http(s) origin.
     */
    public static function resolve(?string $originHeader, ?string $refererHeader): ?string
    {
        $origin = self::normalise($originHeader);
        if ($origin !== null) {
            return $origin;
        }

        // Fall back to the scheme+host+port of the Referer, if any.
        return self::normalise($refererHeader);
    }

    /**
     * If the resolved request origin is in the allowlist, return it
     * (normalised); otherwise null.
     *
     * @param array<int, string> $allowedOrigins Already-normalised origins.
     */
    public static function match(
        ?string $originHeader,
        ?string $refererHeader,
        array $allowedOrigins
    ): ?string {
        $origin = self::resolve($originHeader, $refererHeader);
        if ($origin === null) {
            return null;
        }

        return in_array($origin, $allowedOrigins, true) ? $origin : null;
    }

    /**
     * Normalise a URL/origin to scheme://host[:port], lower-cased, with no
     * path/query/fragment/credentials. Returns null when the input is not a
     * usable http(s) origin.
     */
    public static function normalise(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || $value === 'null') {
            return null;
        }

        $parts = parse_url($value);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $origin = $scheme . '://' . strtolower($parts['host']);
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }
}
