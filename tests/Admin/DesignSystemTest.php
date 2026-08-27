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
            'eye', 'eye-off', 'chevron-down',
            'layout-dashboard', 'file-text', 'inbox', 'mail', 'users',
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

        // Header bar shape (not a docs layout), plus the tab bar beneath it.
        self::assertStringContainsString('osf-header', $nav);
        self::assertStringContainsString('osf-tabnav', $nav);

        // The five primary destinations live in the tab bar (labels are
        // passed as string args to the tab builder in the template).
        foreach (['Dashboard', 'Forms', 'Submissions', 'Email', 'Admins'] as $label) {
            self::assertStringContainsString("'{$label}'", $nav, "Nav is missing the {$label} tab");
        }

        // Account is NOT a tab — it lives on the admin-name link in the header.
        self::assertStringNotContainsString("'Account'", $nav);
        self::assertStringContainsString('osf-admin-name', $nav);

        // Docs link: external, new tab, noopener, book-open icon — in the header.
        self::assertStringContainsString('href="https://opensendform.com"', $nav);
        self::assertStringContainsString('target="_blank"', $nav);
        self::assertStringContainsString('rel="noopener"', $nav);
        self::assertStringContainsString("icon('book-open')", $nav);

        // Theme toggle uses the three Lucide glyphs (not the old text glyph).
        self::assertStringContainsString('data-theme-toggle', $nav);
        self::assertStringContainsString("icon('sun'", $nav);
        self::assertStringContainsString("icon('moon'", $nav);
        self::assertStringContainsString("icon('monitor'", $nav);

        // Each of the five tabs is built with its own Lucide icon argument.
        foreach ([
            'dashboard'   => 'layout-dashboard',
            'forms'       => 'file-text',
            'submissions' => 'inbox',
            'mail'        => 'mail',
            'admins'      => 'users',
        ] as $key => $iconName) {
            self::assertMatchesRegularExpression(
                "/\\\$tab\\('{$key}', '[^']*', '[^']*', '{$iconName}'\\)/",
                $nav,
                "Tab '{$key}' is not wired to the '{$iconName}' icon"
            );
        }

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

    // --- Header: two rows, GitHub-aligned surfaces, one hairline under the
    // second (fix/5d-polish-v3, architect round 3) ------------------------

    public function testHeaderIsTwoRowsOnGithubAlignedSurfacesWithOneHairlineUnderTheSecond(): void
    {
        $css = self::read('public/assets/admin.css');

        self::assertMatchesRegularExpression('/\.osf-header\s*\{([^}]*)\}/s', $css, '.osf-header rule not found');
        preg_match('/\.osf-header\s*\{([^}]*)\}/s', $css, $headerBlock);
        // Top row: near-black --osf-bg-inset, matching github.com's header.
        // Exact token match (word-boundary on the closing paren) so a stray
        // --osf-bg-raised/--osf-bg-overlay can't slip past a loose substring
        // check — those tokens contain "--osf-bg-inset" is not a risk here,
        // but the mirrored --osf-bg-raised exclusion below guards the case
        // that actually broke the tab row.
        self::assertStringContainsString('--osf-bg-inset', $headerBlock[1]);
        self::assertStringNotContainsString(
            '--osf-bg-raised',
            $headerBlock[1],
            'Top row must not reference the raised surface'
        );
        self::assertStringNotContainsString(
            'border-bottom',
            $headerBlock[1],
            'No divider between the header\'s two rows'
        );

        self::assertMatchesRegularExpression('/\.osf-tabnav\s*\{([^}]*)\}/s', $css, '.osf-tabnav rule not found');
        preg_match('/\.osf-tabnav\s*\{([^}]*)\}/s', $css, $tabnavBlock);
        // Tab row: page background --osf-bg, distinct from the top row. Match
        // the exact token (not followed by "-raised"/"-inset"/"-overlay") —
        // assertStringContainsString('--osf-bg', ...) alone would also pass
        // for "--osf-bg-raised", which is exactly the regression this test
        // must catch.
        self::assertMatchesRegularExpression(
            '/background:\s*var\(--osf-bg\)/',
            $tabnavBlock[1],
            'Tab row background must resolve to exactly var(--osf-bg)'
        );
        self::assertStringNotContainsString('--osf-bg-raised', $tabnavBlock[1]);
        self::assertStringNotContainsString('--osf-bg-inset', $tabnavBlock[1]);
        self::assertStringContainsString(
            'border-bottom',
            $tabnavBlock[1],
            'The single hairline sits under the second (tab) row'
        );

        // No surface between the two rows: they must be direct siblings in
        // the markup (header closes, then the tab <nav> opens immediately),
        // so no wrapper element could carry its own (possibly stale/raised)
        // background between them.
        $nav = self::read('templates/admin/_nav.php');
        self::assertMatchesRegularExpression(
            '/<\/header>\s*<nav class="osf-tabnav"/',
            $nav,
            'A wrapper between the header and the tab row could shadow the ruled surfaces'
        );

        // No other rule in the stylesheet backgrounds .osf-header or
        // .osf-tabnav (e.g. a broader "header, nav" or wrapper selector) —
        // each selector must be declared exactly once.
        self::assertSame(1, preg_match_all('/\.osf-header\s*\{/', $css), 'Exactly one .osf-header rule expected');
        self::assertSame(1, preg_match_all('/\.osf-tabnav\s*\{/', $css), 'Exactly one .osf-tabnav rule expected');
    }

    // --- Header/tab bar: full-width surfaces, content aligned to the column

    public function testHeaderAndTabBarSpanTheViewportWithColumnAlignedContent(): void
    {
        $css = self::read('public/assets/admin.css');
        $nav = self::read('templates/admin/_nav.php');

        // The bars themselves (.osf-header/.osf-tabnav) carry no max-width —
        // only their "-inner container" children are column-constrained.
        self::assertDoesNotMatchRegularExpression('/\.osf-header\s*\{[^}]*max-width/s', $css);
        self::assertDoesNotMatchRegularExpression('/\.osf-tabnav\s*\{[^}]*max-width/s', $css);
        self::assertStringContainsString('osf-header-inner container', $nav);
        self::assertStringContainsString('osf-tabnav-inner container', $nav);
    }

    // --- Tab bar: architect-supplied active-tab orange ---------------------

    public function testTabBarUsesTheArchitectSuppliedActiveTabToken(): void
    {
        $css = self::read('public/assets/admin.css');
        self::assertMatchesRegularExpression(
            '/\.osf-tab-link\[aria-current="page"\]\s*\{[^}]*--osf-tab-active/s',
            $css,
            'The active tab underline does not reference --osf-tab-active'
        );

        $tokens = self::read('public/assets/tokens.css');
        self::assertStringContainsString('--osf-tab-active:', $tokens);
        self::assertStringContainsString('#f78166', $tokens);
    }

    // --- Account menu: <details>/<summary>, no standalone logout button ---

    public function testAccountMenuIsADetailsDropdownWithLogoutInsideIt(): void
    {
        $nav = self::read('templates/admin/_nav.php');

        // The admin name is a native <details>/<summary> dropdown (no JS
        // needed to open/close it, CSP-safe) rather than a plain link.
        self::assertStringContainsString('<details class="osf-account-menu">', $nav);
        self::assertStringContainsString('<summary class="osf-nav-link osf-admin-name">', $nav);
        self::assertStringContainsString("icon('chevron-down'", $nav);

        // The panel holds the account link and the logout form as menu items.
        self::assertStringContainsString('class="osf-account-panel"', $nav);
        self::assertStringContainsString('href="/admin/account">Your account</a>', $nav);
        self::assertStringContainsString('action="/admin/logout"', $nav);
        self::assertStringContainsString('osf-account-item--danger', $nav);

        // Exactly one logout form in the nav partial — no standalone logout
        // button sits outside the dropdown.
        self::assertSame(1, substr_count($nav, 'action="/admin/logout"'));
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

    // --- Admins: status toggle switch replaces Deactivate/Reactivate ------

    public function testAdminsStatusColumnIsASwitchNotButtons(): void
    {
        $html = self::read('templates/admin/admins.php');

        self::assertStringNotContainsString('>Deactivate<', $html);
        self::assertStringNotContainsString('>Reactivate<', $html);
        self::assertStringContainsString('class="osf-switch"', $html);
        self::assertStringContainsString('role="switch"', $html);
        self::assertStringContainsString('aria-pressed="true"', $html);
        self::assertStringContainsString('aria-pressed="false"', $html);
        // Disabled state (last active admin) carries an explanatory title.
        self::assertStringContainsString('disabled', $html);
        self::assertStringContainsString('title="The last active admin cannot be deactivated."', $html);

        $css = self::read('public/assets/admin.css');
        self::assertMatchesRegularExpression('/\.osf-switch\s*\{/', $css);
        self::assertMatchesRegularExpression('/\.osf-switch\[aria-pressed="true"\]\s*\{[^}]*--osf-success/s', $css);
    }

    // --- Forms page: equal-width small buttons -----------------------------

    public function testFormsRowActionsAreEqualWidth(): void
    {
        $html = self::read('templates/admin/forms_list.php');
        self::assertMatchesRegularExpression(
            '/class="secondary osf-btn-sm osf-btn-equal"[^>]*>.*?Edit/s',
            $html
        );
        self::assertMatchesRegularExpression(
            '/class="<\?= \$active[^"]*osf-btn-sm osf-btn-equal"/s',
            $html
        );

        $css = self::read('public/assets/admin.css');
        self::assertMatchesRegularExpression('/\.osf-btn-equal\s*\{[^}]*min-width/s', $css);
    }

    // --- Admins: add-admin section spacing ----------------------------------

    public function testAddAdminSectionHasTopSpacing(): void
    {
        $html = self::read('templates/admin/admins.php');
        self::assertMatchesRegularExpression(
            '/<section class="osf-section-top">\s*<h2>Add an admin<\/h2>/',
            $html
        );

        $css = self::read('public/assets/admin.css');
        self::assertMatchesRegularExpression(
            '/\.osf-section-top\s*\{[^}]*margin-top:\s*var\(--osf-space-6\)/s',
            $css
        );
    }
}
