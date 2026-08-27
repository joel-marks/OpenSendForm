<?php

declare(strict_types=1);

/**
 * Shared, side-effect-free helpers for the release build and verify scripts.
 *
 * This file only DEFINES functions — requiring it does nothing on its own, so
 * both bin/build-release.php and bin/verify-release.php can pull it in (via
 * require_once) without triggering any work. It deliberately holds only the
 * mechanical plumbing (zip/unzip, recursive delete, running a command, reading
 * the version constant); the authoritative exclusion/inclusion lists live in
 * each of those two scripts so a test can compare them and catch drift.
 *
 * These scripts run ONLY in the dev container / CI, never in production.
 */

/**
 * Read the single source-of-truth version out of src/Version.php by parsing the
 * `const STRING = '...'` line, with no need to autoload the class. Both scripts
 * use this so "the version" always means the same string.
 */
function osf_release_version(string $versionPhpPath): string
{
    $src = @file_get_contents($versionPhpPath);
    if ($src === false) {
        throw new RuntimeException("Cannot read version file: {$versionPhpPath}");
    }
    if (!preg_match('/const\s+STRING\s*=\s*\'([^\']+)\'/', $src, $m)) {
        throw new RuntimeException("Could not find version constant in {$versionPhpPath}");
    }

    return $m[1];
}

/**
 * Run an external command (given as argv parts, so no shell quoting pitfalls)
 * and throw if it exits non-zero. Returns its stdout.
 */
function osf_run(array $argv, ?string $cwd = null): string
{
    $cmd = implode(' ', array_map('escapeshellarg', $argv));

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, $cwd);
    if (!is_resource($proc)) {
        throw new RuntimeException("Failed to start: {$cmd}");
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    if ($code !== 0) {
        throw new RuntimeException("Command failed ({$code}): {$cmd}\n{$stderr}");
    }

    return $stdout;
}

/**
 * Recursively delete a file or directory. Used to prune excluded paths from the
 * exported tree and to clean up temp directories.
 */
function osf_rrmdir(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }

    $entries = scandir($path);
    foreach ($entries === false ? [] : $entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        osf_rrmdir($path . '/' . $entry);
    }
    @rmdir($path);
}

/**
 * Zip a directory so the archive's single top-level entry is $folderName/.
 * Prefers the PHP zip extension (ZipArchive); falls back to the `zip` CLI on
 * hosts/containers where the extension is not compiled in. Both produce the
 * same layout.
 */
function osf_zip_dir(string $sourceParent, string $folderName, string $zipPath): void
{
    if (is_file($zipPath)) {
        unlink($zipPath);
    }

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot create zip: {$zipPath}");
        }
        $root = $sourceParent . '/' . $folderName;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $zip->addEmptyDir($folderName);
        foreach ($it as $file) {
            $absolute = (string) $file;
            $local = $folderName . '/' . substr($absolute, strlen($root) + 1);
            if ($file->isDir()) {
                $zip->addEmptyDir($local);
            } else {
                $zip->addFile($absolute, $local);
            }
        }
        $zip->close();
        return;
    }

    // Fallback: the `zip` binary. Run it from the parent so paths inside the
    // archive are relative to (and prefixed by) the folder name.
    osf_run(['zip', '-r', '-q', '-X', $zipPath, $folderName], $sourceParent);
}

/**
 * Extract a zip into $destDir. Prefers ZipArchive; falls back to the `unzip`
 * CLI. Mirror image of osf_zip_dir().
 */
function osf_unzip(string $zipPath, string $destDir): void
{
    if (!is_dir($destDir) && !mkdir($destDir, 0775, true) && !is_dir($destDir)) {
        throw new RuntimeException("Cannot create dir: {$destDir}");
    }

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Cannot open zip: {$zipPath}");
        }
        $zip->extractTo($destDir);
        $zip->close();
        return;
    }

    osf_run(['unzip', '-q', '-o', $zipPath, '-d', $destDir]);
}
