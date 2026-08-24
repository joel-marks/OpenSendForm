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
