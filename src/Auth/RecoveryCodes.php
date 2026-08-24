<?php

declare(strict_types=1);

namespace OpenSendForm\Auth;

/**
 * One-time recovery codes for the TOTP second factor.
 *
 * generate() returns a batch of plaintext codes (shown to the admin exactly
 * once) together with their hashes for storage. Codes are stored only as
 * password_hash'es — never plaintext — in a JSON array. consume() verifies a
 * submitted code against the stored set and, on a match, returns the set
 * with that code removed so a code can be used at most once.
 *
 * The alphabet omits visually ambiguous characters (0/O, 1/I/L) so codes
 * copied by hand are unlikely to be mistyped.
 */
final class RecoveryCodes
{
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private PasswordHasher $hasher;

    public function __construct(PasswordHasher $hasher)
    {
        $this->hasher = $hasher;
    }

    /**
     * Generate a batch of recovery codes.
     *
     * @return array{plain: array<int, string>, hashes: array<int, string>}
     *         plain[] are for one-time display; hashes[] are for storage.
     */
    public function generate(int $count = 10, int $length = 10): array
    {
        $plain = [];
        $hashes = [];

        for ($i = 0; $i < $count; $i++) {
            $code = $this->randomCode($length);
            $plain[] = $code;
            $hashes[] = $this->hasher->hash($code);
        }

        return ['plain' => $plain, 'hashes' => $hashes];
    }

    /**
     * Serialise a list of code hashes for the recovery_codes column.
     *
     * @param array<int, string> $hashes
     */
    public function encode(array $hashes): string
    {
        return json_encode(array_values($hashes), JSON_THROW_ON_ERROR);
    }

    /**
     * Decode a stored recovery_codes JSON blob to a list of hashes.
     *
     * @return array<int, string>
     */
    public function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        // Keep only string entries; ignore anything malformed.
        return array_values(array_filter($decoded, 'is_string'));
    }

    /**
     * Attempt to consume a submitted code against the stored hashes.
     *
     * On a match the code's hash is removed and the remaining hashes are
     * returned (single-use). On no match, null is returned and the caller
     * leaves storage untouched.
     *
     * @param array<int, string> $hashes
     * @return array<int, string>|null Remaining hashes, or null if no match.
     */
    public function consume(string $submittedCode, array $hashes): ?array
    {
        $normalised = $this->normalise($submittedCode);
        if ($normalised === '') {
            return null;
        }

        foreach ($hashes as $index => $hash) {
            if ($this->hasher->verify($normalised, $hash)) {
                unset($hashes[$index]);

                return array_values($hashes);
            }
        }

        return null;
    }

    /**
     * Normalise user input: uppercase, drop spaces and dashes so a code
     * copied "abcde-fghij" still matches the stored "ABCDEFGHIJ".
     */
    private function normalise(string $code): string
    {
        return strtoupper(str_replace([' ', '-'], '', trim($code)));
    }

    private function randomCode(int $length): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }
}
