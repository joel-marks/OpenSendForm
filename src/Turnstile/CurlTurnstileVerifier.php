<?php

declare(strict_types=1);

namespace OpenSendForm\Turnstile;

/**
 * Verifies Turnstile tokens by POSTing to Cloudflare's siteverify API with
 * PHP's curl extension (present on stock shared hosting — no new dependency).
 *
 * Timeouts are deliberately short (connect 2s, total 3s): a submission must
 * not hang on a slow verify call. Every transport failure, timeout or
 * malformed body maps to UNAVAILABLE so the caller fails open; only a
 * response that positively says success=false yields INVALID.
 */
final class CurlTurnstileVerifier implements TurnstileVerifierInterface
{
    private const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    private const CONNECT_TIMEOUT_SECONDS = 2;
    private const TIMEOUT_SECONDS = 3;

    public function verify(string $secret, string $token, string $remoteIp): TurnstileResult
    {
        $fields = [
            'secret'   => $secret,
            'response' => $token,
        ];
        // remoteip is optional; only send a non-empty value.
        if ($remoteIp !== '') {
            $fields['remoteip'] = $remoteIp;
        }

        $handle = curl_init(self::ENDPOINT);
        if ($handle === false) {
            return TurnstileResult::UNAVAILABLE;
        }

        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
        ]);

        $body = curl_exec($handle);
        $errno = curl_errno($handle);
        $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        // Transport error, timeout or non-2xx: unreachable → fail open.
        if ($errno !== 0 || !is_string($body) || $httpStatus < 200 || $httpStatus >= 300) {
            return TurnstileResult::UNAVAILABLE;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !array_key_exists('success', $decoded)) {
            // Malformed output: treat as unreachable and fail open.
            return TurnstileResult::UNAVAILABLE;
        }

        return $decoded['success'] === true ? TurnstileResult::VALID : TurnstileResult::INVALID;
    }
}
