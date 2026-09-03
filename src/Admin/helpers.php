<?php

declare(strict_types=1);

namespace OpenSendForm\Admin;

// Escaping helper for admin templates. Defined as a plain function (loaded
// once by TemplateRenderer) so templates can call h(...) directly. Guarded
// so repeated requires are harmless.
if (!function_exists('OpenSendForm\\Admin\\h')) {
    /**
     * HTML-escape a value for safe output in a template.
     */
    function h(string|int|float|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('OpenSendForm\\Admin\\asset')) {
    /**
     * Build a cache-busting URL for a static asset under public/assets/.
     *
     * Appends ?v=<app version> (sourced from the single Version::STRING
     * constant — never a hand-typed version in a template) so that every
     * release forces browsers to re-fetch changed CSS/JS instead of serving a
     * stale cached copy. This is the structural cure for "I still see the old
     * styling after an upgrade / hard reload" reports.
     *
     * The returned URL is safe to emit directly: the path is
     * developer-controlled and the version is a constant, so no user input is
     * ever interpolated.
     */
    function asset(string $path): string
    {
        return $path . '?v=' . \OpenSendForm\Version::STRING;
    }
}

if (!function_exists('OpenSendForm\\Admin\\truncate')) {
    /**
     * Shorten a string to $length characters, appending an ellipsis when cut.
     * Returns '' for null. The result is NOT escaped — pass it through h().
     */
    function truncate(string|null $value, int $length = 80): string
    {
        $value = (string) $value;
        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length) . '…';
    }
}

if (!function_exists('OpenSendForm\\Admin\\statCardToneClass')) {
    /**
     * Map a dashboard stat's value to its -subtle tone modifier class.
     *
     * The tone is decided HERE in PHP (never in JS) so the rendered markup is
     * self-describing and testable:
     *   - value 0            -> info    (blue, "nothing to see") regardless of
     *                                     what the stat measures;
     *   - value != 0, failure stat (failed deliveries, dead letters, and
     *                          anything semantically equivalent) -> danger;
     *   - value != 0, any other stat -> success.
     *
     * All three map onto the -subtle token family in admin.css — a restrained
     * accent, no saturated fills and no coloured numerals.
     */
    function statCardToneClass(int $value, bool $failureStat): string
    {
        if ($value === 0) {
            return 'osf-stat--info';
        }

        return $failureStat ? 'osf-stat--danger' : 'osf-stat--success';
    }
}

if (!function_exists('OpenSendForm\\Admin\\statusBadgeClass')) {
    /**
     * Map a submission status to a badge modifier class.
     */
    function statusBadgeClass(string $status): string
    {
        return match ($status) {
            'sent'     => 'osf-badge--ok',
            'failed'   => 'osf-badge--warn',
            'dead'     => 'osf-badge--danger',
            default    => 'osf-badge--muted',
        };
    }
}
