#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * bin/verify-release.php — prove a built release zip is well-formed.
 *
 * Runs in the DEV CONTAINER / CI. Given a zip (argv[1], or the default
 * dist/opensendform-v{VERSION}.zip), it extracts to a temp dir and asserts:
 *   - no excluded path leaked into the artefact,
 *   - every required path is present (front controller, embed script, the
 *     .htaccess set, autoloader, migrations, bin/osf, LICENSE, INSTALL.txt),
 *   - composer's autoloader actually resolves an app class (a real PHP smoke
 *     test run against the extracted vendor/),
 *   - the version embedded in the artefact matches src/Version.php and the zip
 *     file name.
 * Any failure prints "FAIL: …" and the script exits non-zero.
 *
 * The exclusion list here is verify's own authoritative copy; it must match the
 * one in bin/build-release.php. A PHPUnit test compares the two so they cannot
 * silently drift.
 */

require_once __DIR__ . '/release_lib.php';

/**
 * Paths (relative to the opensendform/ root) that must NOT appear in a release.
 * Kept identical to osf_build_exclusions() in bin/build-release.php.
 *
 * @return array<int, string>
 */
function osf_verify_exclusions(): array
{
    return [
        'tests',
        '.devcontainer',
        '.github',
        '.claude',
        '.git',
        '.gitignore',
        '.gitattributes',
        '.phpunit.cache',
        'phpunit.xml',
        'CLAUDE.md',
        'CONTEXT.md',
        'HISTORY.md',
        'QUESTIONS.md',
        'bin/build-release.php',
        'bin/verify-release.php',
        'bin/release_lib.php',
    ];
}

/**
 * Files (relative to the opensendform/ root) that MUST be present in a release.
 * Glob-free exact paths, except migrations, which are checked separately as a
 * "at least one *.sql" rule.
 *
 * @return array<int, string>
 */
function osf_verify_required(): array
{
    return [
        'public/index.php',
        'public/embed/osf.js',
        'public/.htaccess',
        'vendor/autoload.php',
        'bin/osf',
        'LICENSE',
        'INSTALL.txt',
        'src/Version.php',
        // The fallback-layout deny .htaccess set.
        'src/.htaccess',
        'templates/.htaccess',
        'migrations/.htaccess',
        'bin/.htaccess',
        'var/.htaccess',
        'vendor/.htaccess',
    ];
}

function osf_verify_main(array $argv): int
{
    $projectRoot = dirname(__DIR__);
    $version = osf_release_version($projectRoot . '/src/Version.php');
    $folder = 'opensendform';

    $zipPath = $argv[1] ?? ($projectRoot . '/dist/opensendform-v' . $version . '.zip');
    if (!is_file($zipPath)) {
        fwrite(STDERR, "FAIL: zip not found: {$zipPath}\n");
        return 1;
    }

    $work = sys_get_temp_dir() . '/osf-verify-' . getmypid();
    osf_rrmdir($work);

    $failures = [];
    try {
        osf_unzip($zipPath, $work);
        $root = $work . '/' . $folder;

        if (!is_dir($root)) {
            fwrite(STDERR, "FAIL: zip does not extract to a single {$folder}/ folder\n");
            osf_rrmdir($work);
            return 1;
        }

        // 1. Exclusions absent.
        foreach (osf_verify_exclusions() as $rel) {
            if (file_exists($root . '/' . $rel)) {
                $failures[] = "excluded path present: {$rel}";
            }
        }

        // 2. Required paths present.
        foreach (osf_verify_required() as $rel) {
            if (!file_exists($root . '/' . $rel)) {
                $failures[] = "required path missing: {$rel}";
            }
        }

        // 2b. At least one migration .sql.
        $migrations = glob($root . '/migrations/*.sql');
        if ($migrations === false || $migrations === []) {
            $failures[] = 'no migrations/*.sql present';
        }

        // 3. Autoloader smoke: resolve an app class from the shipped vendor/.
        $smoke = 'require ' . var_export($root . '/vendor/autoload.php', true) . ';'
            . ' exit(class_exists("OpenSendForm\\\\Version") ? 0 : 3);';
        $code = 0;
        try {
            osf_run([PHP_BINARY, '-r', $smoke]);
        } catch (Throwable $e) {
            $code = 1;
        }
        if ($code !== 0) {
            $failures[] = 'autoloader smoke failed: OpenSendForm\\Version did not resolve';
        }

        // 4. Embedded version matches src/Version.php and the file name.
        $embedded = osf_release_version($root . '/src/Version.php');
        if ($embedded !== $version) {
            $failures[] = "embedded version {$embedded} != source version {$version}";
        }
        if (strpos(basename($zipPath), $version) === false) {
            $failures[] = "zip name " . basename($zipPath) . " does not contain version {$version}";
        }
        $installTxt = (string) @file_get_contents($root . '/INSTALL.txt');
        if (strpos($installTxt, $version) === false) {
            $failures[] = 'INSTALL.txt does not mention the version';
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
        osf_rrmdir($work);
        return 1;
    }

    osf_rrmdir($work);

    if ($failures !== []) {
        foreach ($failures as $f) {
            fwrite(STDERR, "FAIL: {$f}\n");
        }
        fwrite(STDERR, sprintf("Release verification FAILED (%d problem(s)).\n", count($failures)));
        return 1;
    }

    fwrite(STDOUT, "OK: " . basename($zipPath) . " passed all release checks (v{$version}).\n");

    return 0;
}

// Only run when invoked directly; require()-ing this file (e.g. from the
// manifest-sync test) just loads the functions above.
if (PHP_SAPI === 'cli' && isset($_SERVER['argv'][0])
    && realpath($_SERVER['argv'][0]) === realpath(__FILE__)) {
    exit(osf_verify_main($_SERVER['argv']));
}
