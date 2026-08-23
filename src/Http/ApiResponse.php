<?php

declare(strict_types=1);

namespace OpenSendForm\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Builders for the frozen public JSON response contract.
 *
 * Success: {"ok":true[, ...extra]}
 * Failure: {"ok":false,"error":{"code":"...","message":"..."}}
 *
 * Error codes are stable, machine-readable snake_case; messages are for
 * humans and may change. The future embed JS is written against this
 * shape, so it must not drift.
 */
final class ApiResponse
{
    /**
     * A success response ({"ok":true}) with an optional merged payload.
     *
     * @param array<string, mixed> $extra Additional top-level keys (e.g. a token).
     */
    public static function success(
        ResponseInterface $response,
        array $extra = [],
        int $status = 200
    ): ResponseInterface {
        return self::json($response, array_merge(['ok' => true], $extra), $status);
    }

    /**
     * A failure response carrying a stable code and human-readable message.
     */
    public static function error(
        ResponseInterface $response,
        int $status,
        string $code,
        string $message
    ): ResponseInterface {
        return self::json($response, [
            'ok'    => false,
            'error' => ['code' => $code, 'message' => $message],
        ], $status);
    }

    /**
     * Echo CORS headers for an allowed origin. No-op when $origin is null so
     * responses issued before origin validation carry no CORS headers.
     */
    public static function withCors(ResponseInterface $response, ?string $origin): ResponseInterface
    {
        if ($origin === null) {
            return $response->withHeader('Vary', 'Origin');
        }

        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Vary', 'Origin');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function json(
        ResponseInterface $response,
        array $payload,
        int $status
    ): ResponseInterface {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
