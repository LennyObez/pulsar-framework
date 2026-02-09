<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Boundary;

use function array_slice;

use const DIRECTORY_SEPARATOR;

use function dirname;
use function file_get_contents;
use function glob;

use const GLOB_ONLYDIR;

use function implode;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function preg_match;
use function sprintf;
use function str_contains;
use function ucfirst;

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
                if (preg_match('/extensions[\\\\\/]([^\\\\\/]+)[\\\\\/]src$/', $extDir, $matches) === 1) {
                    $extName = $matches[1];
                    $namespace = 'Pulsar\\\\Extension\\\\' . ucfirst($extName);

                    if (!str_contains($content, $namespace)) {
                        $uncovered[] = $extName;
                    }
                }
            }
        }

        self::assertEmpty(
            $uncovered,
            "Extensions not covered by Deptrac config:\n- " . implode("\n- ", $uncovered),
        );
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
