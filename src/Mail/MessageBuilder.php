<?php

declare(strict_types=1);

namespace OpenSendForm\Mail;

/**
 * Turns a form plus a stored submission's fields into a safe outbound
 * message (subject + plain-text body + optional Reply-To).
 *
 * This class is the last line of defence for the mail security policy, in
 * addition to (never instead of) PHPMailer's own protections:
 *
 *  - From is decided by the delivery layer, never here.
 *  - Reply-To is ONLY the submitter's `email` field, and only when it is a
 *    syntactically valid address; a header-injection attempt fails the
 *    filter and yields no Reply-To at all.
 *  - The Subject is derived from the form name with all control characters
 *    (newlines included) collapsed away, so nothing the submitter typed can
 *    reach or forge a header.
 *  - The body lists the submitted fields as plain text; field names are
 *    flattened to a single line and both names and values have control
 *    characters stripped and are truncated at a sane cap.
 */
final class MessageBuilder
{
    /** Maximum characters kept for a single field value in the body. */
    public const VALUE_CAP = 5000;

    /** Maximum characters kept for a field name (or the subject's form name). */
    public const NAME_CAP = 200;

    /**
     * Build the message for a submission.
     *
     * @param array<string,mixed>  $form   The owning form row (uses 'name').
     * @param array<string,mixed>  $fields The submitted user fields.
     */
    public function build(array $form, array $fields): BuiltMessage
    {
        $formName = isset($form['name']) ? (string) $form['name'] : '';

        return new BuiltMessage(
            $this->buildSubject($formName),
            $this->buildBody($formName, $fields),
            $this->extractReplyTo($fields)
        );
    }

    /**
     * Subject line: a fixed prefix plus the sanitised form name.
     */
    public function buildSubject(string $formName): string
    {
        $name = $this->sanitiseHeaderText($formName);
        $subject = $name === '' ? 'New form submission' : 'New form submission: ' . $name;

        return $this->truncate($subject, self::NAME_CAP + 32);
    }

    /**
     * Plain-text body: an intro line then one "name: value" line per field.
     *
     * @param array<string,mixed> $fields
     */
    public function buildBody(string $formName, array $fields): string
    {
        $safeName = $this->sanitiseHeaderText($formName);

        $lines = [];
        $lines[] = 'You have received a new form submission.';
        $lines[] = '';
        if ($safeName !== '') {
            $lines[] = 'Form: ' . $safeName;
            $lines[] = '';
        }

        if ($fields === []) {
            $lines[] = '(no fields were submitted)';
        } else {
            foreach ($fields as $name => $value) {
                $lines[] = $this->sanitiseFieldName((string) $name)
                    . ': ' . $this->sanitiseFieldValue($value);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Extract a Reply-To from the submitter's `email` field, or null.
     */
    public function extractReplyTo(array $fields): ?string
    {
        $email = $fields['email'] ?? null;
        if (!is_string($email)) {
            return null;
        }

        $valid = filter_var(trim($email), FILTER_VALIDATE_EMAIL);

        return $valid === false ? null : $valid;
    }

    /**
     * Collapse a header-context string to a single trimmed line with every
     * control character (newlines, tabs, C0, DEL) removed.
     */
    private function sanitiseHeaderText(string $text): string
    {
        // Control-char ranges are single ASCII bytes; no /u so valid UTF-8
        // multibyte sequences (bytes >= 0x80) are left intact.
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text);
        $text = (string) preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Field name for the body: single line, control-free, capped, never blank.
     */
    private function sanitiseFieldName(string $name): string
    {
        $name = $this->sanitiseHeaderText($name);
        if ($name === '') {
            $name = '(unnamed)';
        }

        return $this->truncate($name, self::NAME_CAP);
    }

    /**
     * Field value for the body: line endings normalised, control characters
     * (bar tab and newline) stripped, value truncated at the cap.
     */
    private function sanitiseFieldValue(mixed $value): string
    {
        $value = $this->stringify($value);
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        // Strip C0 controls and DEL but keep tab (\x09) and newline (\x0A).
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        return $this->truncate($value, self::VALUE_CAP);
    }

    /**
     * Coerce an arbitrary stored value to a string without emitting PHP
     * notices or leaking type internals.
     */
    private function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        // Arrays/objects should not appear in normalised submissions; render
        // them defensively rather than throwing inside the mail path.
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Character-accurate truncation with an explicit marker when it bites.
     */
    private function truncate(string $text, int $cap): string
    {
        if (mb_strlen($text) <= $cap) {
            return $text;
        }

        return mb_substr($text, 0, $cap) . '… [truncated]';
    }
}
