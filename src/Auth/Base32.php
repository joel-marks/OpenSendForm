<?php

declare(strict_types=1);

namespace OpenSendForm\Auth;

use InvalidArgumentException;

/**
 * RFC 4648 base32 (the alphabet used by authenticator apps).
 *
 * encode() emits the canonical A–Z2–7 alphabet with NO '=' padding, which
 * is what otpauth:// secrets use. decode() is padding-agnostic: it accepts
 * input with or without '=' padding, tolerates surrounding whitespace, and
 * is case-insensitive. It has no external dependencies (pure bit shuffling)
 * so it runs on stock shared hosting.
 */
final class Base32
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Encode raw bytes to an unpadded base32 string.
     */
    public static function encode(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            // The final group is right-padded with zero bits to five, exactly
            // as RFC 4648 specifies before mapping to a symbol.
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $output .= self::ALPHABET[bindec($chunk)];
        }

        return $output;
    }

    /**
     * Decode a base32 string (padded or unpadded) back to raw bytes.
     *
     * @throws InvalidArgumentException on a character outside the alphabet.
     */
    public static function decode(string $input): string
    {
        // Uppercase, drop padding and any whitespace; then it is pure symbols.
        $input = strtoupper($input);
        $input = str_replace(['=', ' ', "\t", "\n", "\r"], '', $input);
        if ($input === '') {
            return '';
        }

        $map = array_flip(str_split(self::ALPHABET));

        $bits = '';
        foreach (str_split($input) as $char) {
            if (!isset($map[$char])) {
                throw new InvalidArgumentException("Invalid base32 character: {$char}");
            }
            $bits .= str_pad(decbin($map[$char]), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            // A trailing run of fewer than 8 bits is padding introduced by the
            // 5-bit grouping; discard it rather than emit a partial byte.
            if (strlen($chunk) < 8) {
                break;
            }
            $bytes .= chr(bindec($chunk));
        }

        return $bytes;
    }
}
