<?php

declare(strict_types=1);

namespace OpenSendForm\Security;

use OpenSendForm\Clock\Clock;

/**
 * Issues and verifies submit tokens.
 *
 * A token is minted when a page fetches it and presented back with the
 * submission. It proves two things cheaply and statelessly: that some
 * measurable time passed between page load and submit (bots submit
 * instantly), and that the token was issued by this server for this
 * form (the HMAC). No storage is needed — the timestamp travels in the
 * token and the signature makes it unforgeable.
 *
 * Wire format: "<unix_ts>.<hex hmac_sha256(ts . form_key, secret)>".
 */
final class SubmitToken
{
    public const VALID = 'valid';
    public const TOO_YOUNG = 'too_young';
    public const EXPIRED = 'expired';
    public const INVALID = 'invalid';

    private string $secret;
    private Clock $clock;
    private int $minAgeSeconds;
    private int $maxAgeSeconds;

    public function __construct(
        string $secret,
        Clock $clock,
        int $minAgeSeconds,
        int $maxAgeSeconds
    ) {
        $this->secret = $secret;
        $this->clock = $clock;
        $this->minAgeSeconds = $minAgeSeconds;
        $this->maxAgeSeconds = $maxAgeSeconds;
    }

    /**
     * Issue a fresh token for a form, stamped with the current time.
     */
    public function issue(string $formKey): string
    {
        $ts = $this->clock->now();

        return $ts . '.' . $this->sign($ts, $formKey);
    }

    /**
     * Verify a token against a form.
     *
     * Signature is checked before age so a forged token is always INVALID
     * regardless of its (attacker-chosen) timestamp.
     *
     * @return string One of the VALID / TOO_YOUNG / EXPIRED / INVALID
     *                constants.
     */
    public function verify(string $token, string $formKey): string
    {
        $dot = strpos($token, '.');
        if ($dot === false) {
            return self::INVALID;
        }

        $tsPart = substr($token, 0, $dot);
        $sigPart = substr($token, $dot + 1);

        // Timestamp must be a non-empty run of digits (ctype_digit rejects
        // empty strings, signs and whitespace).
        if (!ctype_digit($tsPart)) {
            return self::INVALID;
        }

        $ts = (int) $tsPart;
        $expected = $this->sign($ts, $formKey);

        if (!hash_equals($expected, $sigPart)) {
            return self::INVALID;
        }

        $age = $this->clock->now() - $ts;

        if ($age < $this->minAgeSeconds) {
            return self::TOO_YOUNG;
        }

        if ($age > $this->maxAgeSeconds) {
            return self::EXPIRED;
        }

        return self::VALID;
    }

    private function sign(int $ts, string $formKey): string
    {
        return hash_hmac('sha256', $ts . $formKey, $this->secret);
    }
}
