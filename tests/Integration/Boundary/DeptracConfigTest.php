<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Boundary;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_slice;
use function basename;
use function dirname;
use function file_get_contents;
use function glob;
use function implode;
use function preg_match;
use function sprintf;
use function str_contains;
use function str_replace;

use const DIRECTORY_SEPARATOR;
use const GLOB_ONLYDIR;

#[CoversNothing]
final class DeptracConfigTest extends TestCase
{
    private static string $rootDir;

    private static string $deptracPath;

    public static function setUpBeforeClass(): void
    {
        self::$rootDir = dirname(__DIR__, 3);
        self::$deptracPath = self::$rootDir . DIRECTORY_SEPARATOR . 'tools'
            . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'deptrac.yaml';
    }

    #[Test]
    public function config_file_exists_and_is_valid_yaml(): void
    {
        self::assertFileExists(self::$deptracPath);

        $content = file_get_contents(self::$deptracPath);
        self::assertNotFalse($content);
        self::assertStringContainsString('deptrac:', $content);
        self::assertStringContainsString('layers:', $content);
        self::assertStringContainsString('ruleset:', $content);
    }

    #[Test]
    public function all_src_modules_have_public_and_internal_layers(): void
    {
        $content = (string) file_get_contents(self::$deptracPath);

        $srcDirs = glob(
            self::$rootDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . '*',
            GLOB_ONLYDIR,
        );

        $uncoveredPublic = [];
        $uncoveredInternal = [];

        if ($srcDirs !== false) {
            foreach ($srcDirs as $dir) {
                $moduleName = basename($dir);

                // Check public layer pattern
                $publicPattern = 'Pulsar\\\\' . $moduleName . '\\\\';
                if (!str_contains($content, $publicPattern)) {
                    $uncoveredPublic[] = $moduleName;
                }

                // Check internal layer name
                $internalLayerName = $moduleName . '_Internal';
                if (!str_contains($content, $internalLayerName)) {
                    $uncoveredInternal[] = $moduleName;
                }
            }
        }

        self::assertEmpty(
            $uncoveredPublic,
            "Modules missing public layer in deptrac.yaml:\n- " . implode("\n- ", $uncoveredPublic),
        );

        self::assertEmpty(
            $uncoveredInternal,
            "Modules missing internal layer in deptrac.yaml:\n- " . implode("\n- ", $uncoveredInternal),
        );
    }

    #[Test]
    public function all_extensions_are_covered(): void
    {
        $content = (string) file_get_contents(self::$deptracPath);

        $extDirs = glob(
            self::$rootDir . DIRECTORY_SEPARATOR . 'extensions' . DIRECTORY_SEPARATOR . '*'
            . DIRECTORY_SEPARATOR . 'src',
            GLOB_ONLYDIR,
        );

        $uncovered = [];

        if ($extDirs !== false) {
            foreach ($extDirs as $extDir) {
                $pattern = $this->extensionNamespacePattern($extDir);

                if ($pattern === null) {
                    continue;
                }

                if (!str_contains($content, $pattern)) {
                    $uncovered[] = basename(dirname($extDir));
                }
            }
        }

        self::assertEmpty(
            $uncovered,
            "Extensions not covered by Deptrac config:\n- " . implode("\n- ", $uncovered),
        );
    }

    /**
     * Resolve an extension's root namespace (e.g. "Pulsar\\Extension\\OpenTelemetry")
     * from its actual source, in the regex-escaped form deptrac.yaml uses. Reading
     * the declared namespace avoids guessing it from the directory name, which
     * fails for camelCase names such as "opentelemetry" -> "OpenTelemetry".
     */
    private function extensionNamespacePattern(string $extSrcDir): ?string
    {
        $candidates = glob($extSrcDir . DIRECTORY_SEPARATOR . '*.php') ?: [];

        if ($candidates === []) {
            $candidates = glob(
                $extSrcDir . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*.php',
            ) ?: [];
        }

        foreach ($candidates as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match('/namespace\s+(Pulsar\\\\Extension\\\\[A-Za-z0-9_]+)/', $source, $matches) === 1) {
                // deptrac.yaml stores namespaces as regex with escaped backslashes.
                return str_replace('\\', '\\\\', $matches[1]);
            }
        }

        return null;
    }

    #[Test]
    public function internal_layers_have_restricted_rulesets(): void
    {
        $content = (string) file_get_contents(self::$deptracPath);

        // Internal layers should NOT depend on other internal layers
        // Check that no _Internal layer appears in another _Internal's ruleset
        $srcDirs = glob(
            self::$rootDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . '*',
            GLOB_ONLYDIR,
        );

        if ($srcDirs === false) {
            return;
        }

        foreach ($srcDirs as $dir) {
            $moduleName = basename($dir);
            $internalName = $moduleName . '_Internal';

            // Find the ruleset line for this internal layer
            $pattern = '/^\s+' . preg_quote($internalName, '/') . ':\s*\[([^\]]+)\]/m';
            if (preg_match($pattern, $content, $matches) === 1) {
                $deps = $matches[1];

                // No other _Internal layer should appear in deps
                foreach ($srcDirs as $otherDir) {
                    $otherModule = basename($otherDir);
                    if ($otherModule === $moduleName) {
                        continue;
                    }

                    $otherInternal = $otherModule . '_Internal';
                    self::assertStringNotContainsString(
                        $otherInternal,
                        $deps,
                        "{$internalName} ruleset must not include {$otherInternal}",
                    );
                }
            }
        }
    }

    #[Test]
    public function deptrac_analyse_passes(): void
    {
        $output = [];
        exec(
            sprintf(
                'cd %s && composer boundary:deptrac 2>&1',
                escapeshellarg(self::$rootDir),
            ),
            $output,
            $exitCode,
        );

        self::assertSame(
            0,
            $exitCode,
            "Deptrac analysis failed:\n" . implode("\n", array_slice($output, -20)),
        );
    }

    #[Test]
    public function boundary_custom_script_passes(): void
    {
        $output = [];
        exec(
            sprintf(
                'php %s 2>&1',
                escapeshellarg(self::$rootDir . '/scripts/boundary_check.php'),
            ),
            $output,
            $exitCode,
        );

        self::assertSame(
            0,
            $exitCode,
            "Custom boundary check failed:\n" . implode("\n", $output),
        );
    }
}
