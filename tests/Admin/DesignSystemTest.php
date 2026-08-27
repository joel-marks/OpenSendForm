<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Admin;

use PHPUnit\Framework\TestCase;

use function OpenSendForm\Admin\icon;

/**
 * Contract tests for the increment 5d design system. These are file-level
 * assertions (no HTTP harness needed): the single-source-of-colour rule, the
 * blocking theme bootstrap ordering, the vendored icon helper, the top-nav /
 * no-sidebar shape and the responsive card-collapse hooks. The HTTP-rendered
 * behaviour (CSP, asset refs, live nav) is covered by AdminUiTest.
 */
final class DesignSystemTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function read(string $relative): string
    {
        return (string) file_get_contents(self::root() . '/' . $relative);
    }

    // --- Single source of colour ------------------------------------------

    /**
     * The ONLY stylesheet allowed to carry literal colour values is
     * tokens.css. Every template, admin.css and the JS enhancers must consume
     * the --osf-* tokens instead. vendor/qrcode.js and the out-of-scope embed
     * (public/embed/osf.js) are the documented exemptions.
     */
    public function testNoHardcodedColoursOutsideTokens(): void
    {
        $files = array_merge(
            glob(self::root() . '/templates/admin/*.php'),
            glob(self::root() . '/templates/install/*.php'),
            glob(self::root() . '/public/assets/*.css'),
            glob(self::root() . '/public/assets/*.js')
        );

        $exempt = [
            self::root() . '/public/assets/tokens.css',        // the token contract itself
            self::root() . '/public/assets/vendor/qrcode.js',  // vendored dependency (not in glob, listed for clarity)
        ];

        // A hex colour is exactly 3/4/6/8 hex digits after '#'. HTML numeric
        // entities (&#10; etc.) are stripped first so they never false-match.
        $hex = '/#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{4}|[0-9a-fA-F]{3})\b/';
        $func = '/\b(?:rgb|rgba|hsl|hsla)\s*\(/i';
        // Named CSS colours, matched only as a CSS value (in .css/.js files).
        // The trailing (?![\w-]) stops hyphenated properties like
        // "white-space" or "border-*" from false-matching a colour name.
        $named = '/[:\s(](?:white|black|red|green|blue|yellow|orange|purple|pink|'
            . 'gray|grey|silver|gold|brown|cyan|magenta|maroon|navy|olive|teal|'
            . 'lime|aqua|fuchsia|coral|crimson|indigo|violet|khaki|salmon)(?![\w-])/i';

        foreach ($files as $file) {
            if (in_array($file, $exempt, true)) {
                continue;
            }
            $raw = (string) file_get_contents($file);
            $stripped = preg_replace('/&#\d+;/', '', $raw);

            self::assertDoesNotMatchRegularExpression(
                $hex,
                $stripped,
                "Hardcoded hex colour in " . basename($file) . " — use an --osf-* token from tokens.css"
            );
            self::assertDoesNotMatchRegularExpression(
                $func,
                $stripped,
                "Hardcoded rgb/hsl colour in " . basename($file) . " — use an --osf-* token from tokens.css"
            );

            if (preg_match('/\.(css|js)$/', $file)) {
                self::assertDoesNotMatchRegularExpression(
                    $named,
                    $stripped,
                    "Named CSS colour in " . basename($file) . " — use an --osf-* token from tokens.css"
                );
            }
        }
    }

    public function testTokensDefineTheFullContractForBothSchemes(): void
    {
        $tokens = self::read('public/assets/tokens.css');

        // Dark default on :root, light under [data-theme="light"].
        self::assertStringContainsString(':root', $tokens);
        self::assertStringContainsString('[data-theme="light"]', $tokens);

        // A representative slice of the canonical contract must be present.
        foreach ([
            '--osf-bg', '--osf-bg-raised', '--osf-bg-inset', '--osf-bg-overlay',
            '--osf-text', '--osf-text-muted', '--osf-text-subtle', '--osf-text-on-accent',
            '--osf-border', '--osf-border-muted',
            '--osf-accent', '--osf-accent-emphasis', '--osf-accent-subtle',
            '--osf-success', '--osf-success-subtle', '--osf-warning', '--osf-warning-subtle',
            '--osf-danger', '--osf-danger-subtle', '--osf-info', '--osf-info-subtle',
            '--osf-focus-ring',
            '--osf-font-body', '--osf-font-heading', '--osf-font-mono',
            '--osf-radius', '--osf-radius-lg',
        ] as $token) {
            self::assertStringContainsString($token, $tokens, "tokens.css is missing {$token}");
        }
    }

    // --- Theme bootstrap: no flash of the wrong theme ---------------------

    /**
     * theme-init.js must be the first HTML element inside <head> so the stored
     * theme is applied before first paint. (PHP comment blocks don't count as
     * elements; strip them before checking.)
     */
    public function testThemeInitIsFirstInHead(): void
    {
        foreach (['templates/admin/layout.php', 'templates/install/layout.php'] as $layout) {
            $raw = self::read($layout);
            $noPhp = preg_replace('/<\?php.*?\?>/s', '', $raw);
            self::assertMatchesRegularExpression(
                '#<head>\s*<script src="/assets/theme-init\.js"></script>#',
                (string) $noPhp,
                "theme-init.js is not the first element in <head> of {$layout}"
            );
        }
    }

    public function testThemeInitReadsStorageAndSetsAttributes(): void
    {
        $js = self::read('public/assets/theme-init.js');
        self::assertStringContainsString("'osf-theme'", $js);
        self::assertStringContainsString('data-theme', $js);
        self::assertStringContainsString('data-theme-mode', $js);
        self::assertStringContainsString('prefers-color-scheme', $js);
    }

    // --- Vendored Lucide icons --------------------------------------------

    public function testIconHelperRendersValidInlineSvg(): void
    {
        require_once self::root() . '/src/Admin/helpers.php';
        require_once self::root() . '/src/Admin/icons.php';

        $required = [
            'sun', 'moon', 'monitor', 'copy', 'download', 'trash-2', 'pencil',
            'check', 'x', 'alert-triangle', 'info', 'book-open', 'log-out',
            'eye', 'eye-off',
        ];

        foreach ($required as $name) {
            $svg = icon($name);
            self::assertStringStartsWith('<svg', $svg, "icon('{$name}') did not render an <svg>");
            self::assertStringContainsString('viewBox="0 0 24 24"', $svg);
            self::assertStringContainsString('stroke="currentColor"', $svg);
            self::assertStringEndsWith('</svg>', $svg, "icon('{$name}') is not a closed <svg>");
        }

        // Unknown icons render nothing (fail closed, no broken markup).
        self::assertSame('', icon('no-such-icon'));

        // A label makes the icon non-decorative.
        self::assertStringContainsString('role="img"', icon('trash-2', '', 'Delete'));
        self::assertStringContainsString('aria-label="Delete"', icon('trash-2', '', 'Delete'));

        // The ISC licence note is retained in the vendored file.
        $iconsFile = self::read('src/Admin/icons.php');
        self::assertStringContainsString('ISC', $iconsFile);
        self::assertStringContainsString('Lucide', $iconsFile);
    }

    // --- Top nav in the header, no sidebar --------------------------------

    public function testNavIsATopHeaderWithDocsLinkAndNoSidebar(): void
    {
        $nav = self::read('templates/admin/_nav.php');

        // Header bar shape (not a docs layout).
        self::assertStringContainsString('osf-header', $nav);
        self::assertStringContainsString('osf-nav', $nav);

        // The six primary destinations remain in the top bar (labels are
        // passed as string args to the link builder in the template).
        foreach (['Dashboard', 'Forms', 'Submissions', 'Email', 'Admins', 'Account'] as $label) {
            self::assertStringContainsString("'{$label}'", $nav, "Nav is missing the {$label} link");
        }

        // Docs link: external, new tab, noopener, book-open icon.
        self::assertStringContainsString('href="https://opensendform.com"', $nav);
        self::assertStringContainsString('target="_blank"', $nav);
        self::assertStringContainsString('rel="noopener"', $nav);
        self::assertStringContainsString("icon('book-open')", $nav);

        // Theme toggle uses the three Lucide glyphs (not the old text glyph).
        self::assertStringContainsString('data-theme-toggle', $nav);
        self::assertStringContainsString("icon('sun'", $nav);
        self::assertStringContainsString("icon('moon'", $nav);
        self::assertStringContainsString("icon('monitor'", $nav);

        // No docs-style furniture anywhere in the admin templates.
        foreach (array_merge(
            glob(self::root() . '/templates/admin/*.php'),
            [self::root() . '/templates/install/layout.php']
        ) as $file) {
            $html = (string) file_get_contents($file);
            self::assertStringNotContainsString('<aside', $html, 'No sidebar in ' . basename($file));
            self::assertStringNotContainsString('role="complementary"', $html, 'No sidebar in ' . basename($file));
            self::assertDoesNotMatchRegularExpression('/class="[^"]*sidebar/i', $html, 'No sidebar in ' . basename($file));
        }
    }

    // --- Responsive tables collapse to cards ------------------------------

    public function testDataTablesCarryCardCollapseHooks(): void
    {
        $tableTemplates = [
            'templates/admin/dashboard.php',
            'templates/admin/forms_list.php',
            'templates/admin/submissions.php',
            'templates/admin/admins.php',
            'templates/install/welcome.php',
        ];

        foreach ($tableTemplates as $tpl) {
            $html = self::read($tpl);
            self::assertStringContainsString('class="osf-table"', $html, "{$tpl} table is not marked osf-table");
            self::assertStringContainsString('data-label="', $html, "{$tpl} cells carry no data-label for card collapse");
        }

        // The stylesheet actually collapses them at narrow widths using the
        // data-label values as the per-cell headings.
        $css = self::read('public/assets/admin.css');
        self::assertMatchesRegularExpression('/@media\s*\(max-width:\s*640px\)/', $css);
        self::assertStringContainsString('attr(data-label)', $css);
    }

    public function testSubmissionErrorsAreExpandable(): void
    {
        foreach (['templates/admin/submissions.php', 'templates/admin/dashboard.php'] as $tpl) {
            $html = self::read($tpl);
            // No-JS-safe expander for the full error text.
            self::assertStringContainsString('<details class="osf-error-detail">', $html, "{$tpl} error cell is not expandable");
            self::assertStringContainsString('<summary>', $html);
        }
    }
}
