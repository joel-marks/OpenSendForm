<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Assert;

/**
 * Shared regression check for the fix/5d-polish-v3 field-spacing audit: every
 * visible <input>/<select>/<textarea> in a rendered admin/installer page must
 * sit inside an .osf-field wrapper. Hidden inputs and the CSRF field are
 * exempt (they carry no visible label/spacing concerns).
 */
final class AdminUiFieldWrapperAssertions
{
    public static function assertNoOrphanControls(string $html, string $context): void
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        foreach (['input', 'select', 'textarea'] as $tag) {
            foreach ($xpath->query('//' . $tag) as $node) {
                if (!($node instanceof DOMElement)) {
                    continue;
                }
                if ($tag === 'input') {
                    $type = strtolower($node->getAttribute('type'));
                    if ($type === 'hidden' || $node->getAttribute('name') === '_csrf') {
                        continue;
                    }
                }

                $wrapped = false;
                for ($parent = $node->parentNode; $parent !== null; $parent = $parent->parentNode) {
                    if (
                        $parent instanceof DOMElement
                        && in_array('osf-field', explode(' ', $parent->getAttribute('class')), true)
                    ) {
                        $wrapped = true;
                        break;
                    }
                }

                $name = $node->getAttribute('name') ?: $node->getAttribute('id') ?: '(unnamed)';
                Assert::assertTrue(
                    $wrapped,
                    "{$context}: <{$tag} name=\"{$name}\"> is not inside an .osf-field wrapper"
                );
            }
        }
    }
}
