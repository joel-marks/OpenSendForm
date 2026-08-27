<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Release;

use PHPUnit\Framework\TestCase;

/**
 * The release build (bin/build-release.php) and the release verifier
 * (bin/verify-release.php) each keep their OWN copy of the exclusion list: the
 * build prunes those paths, the verifier asserts they are gone. If the two ever
 * drift, a release could ship a path the verifier no longer checks for. This
 * test parses both scripts straight from source (so it never has to execute the
 * build) and asserts the two exclusion lists are identical.
 */
final class ReleaseManifestTest extends TestCase
{
    private string $buildScript;
    private string $verifyScript;

    protected function setUp(): void
    {
        $bin = dirname(__DIR__, 2) . '/bin';
        $this->buildScript = $bin . '/build-release.php';
        $this->verifyScript = $bin . '/verify-release.php';
    }

    public function testExclusionListsStayInSync(): void
    {
        $build = $this->stringListFrom($this->buildScript, 'osf_build_exclusions');
        $verify = $this->stringListFrom($this->verifyScript, 'osf_verify_exclusions');

        self::assertNotEmpty($build, 'build exclusion list should not be empty');

        sort($build);
        sort($verify);
        self::assertSame(
            $build,
            $verify,
            'build-release.php and verify-release.php exclusion lists have drifted apart.'
        );
    }

    public function testExclusionsCoverTheKnownDevOnlyPaths(): void
    {
        $build = $this->stringListFrom($this->buildScript, 'osf_build_exclusions');

        // A representative spot-check: the things a release must never contain.
        foreach (['tests', '.github', '.claude', 'phpunit.xml', 'CLAUDE.md'] as $expected) {
            self::assertContains($expected, $build);
        }
    }

    public function testRequiredListNamesTheCriticalArtefacts(): void
    {
        $required = $this->stringListFrom($this->verifyScript, 'osf_verify_required');

        foreach (['public/index.php', 'vendor/autoload.php', 'bin/osf', 'LICENSE', 'INSTALL.txt'] as $expected) {
            self::assertContains($expected, $required);
        }
    }

    /**
     * Extract the single-quoted string literals returned by a named function in
     * a PHP source file, using the tokenizer — no execution of the file, so its
     * shebang and its (guarded) build/verify entry point never run.
     *
     * @return array<int, string>
     */
    private function stringListFrom(string $file, string $function): array
    {
        $source = file_get_contents($file);
        self::assertNotFalse($source, "cannot read {$file}");

        $tokens = token_get_all($source);
        $count = count($tokens);

        // Locate `function <name>`.
        $i = 0;
        for (; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if ($j < $count && is_array($tokens[$j])
                && $tokens[$j][0] === T_STRING && $tokens[$j][1] === $function) {
                $i = $j;
                break;
            }
        }
        self::assertLessThan($count, $i, "function {$function} not found in {$file}");

        // Walk the function body, collecting quoted string literals until the
        // brace depth returns to zero.
        $depth = 0;
        $started = false;
        $values = [];
        for (; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === '{') {
                $depth++;
                $started = true;
                continue;
            }
            if ($token === '}') {
                $depth--;
                if ($started && $depth === 0) {
                    break;
                }
                continue;
            }
            if ($started && is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $values[] = trim($token[1], "'\"");
            }
        }

        return $values;
    }
}
