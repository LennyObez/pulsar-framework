<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_filter;
use function array_keys;
use function array_map;
use function count;
use function dirname;
use function end;
use function explode;
use function file_get_contents;
use function implode;
use function is_dir;
use function preg_match;
use function preg_match_all;
use function preg_split;
use function sprintf;
use function str_contains;
use function str_replace;
use function trim;

/**
 * A contract test proves an interface is implementable. It proves nothing about the
 * classes that ship.
 *
 * `#[CoversNothing]` on an interface test is accurate — an interface has no executable
 * code — but it also makes the interface look tested while its implementations may not
 * be. That gap is invisible: the suite is green, the coverage report attributes nothing
 * to the test, and no one is told which shipped class was never exercised.
 *
 * This is the rule the gap needs: an interface exercised only by contract must have at
 * least one concrete implementation carrying its own `#[CoversClass]`.
 *
 * It found DbTicketRepository — 334 lines, twelve public methods, no test at all — and
 * TicketService, exercised by seventeen tests that declared no coverage target.
 */
#[CoversNothing]
final class ContractTestCoverageTest extends TestCase
{
    #[Test]
    public function everyContractTestedInterfaceHasACoveredImplementation(): void
    {
        $root = dirname(__DIR__, 3);

        $interfaces = self::interfacesTestedOnlyByContract($root);
        $implementations = self::concreteImplementations($root);
        $covered = self::classesDeclaredCovered($root);

        $gaps = [];

        foreach ($interfaces as $short) {
            $concrete = $implementations[$short] ?? [];

            // No shipped implementation: the contract test stands alone legitimately.
            if ($concrete === []) {
                continue;
            }

            $uncovered = array_filter($concrete, static fn(string $class): bool => !isset($covered[$class]));

            if (count($uncovered) === count($concrete)) {
                $gaps[] = sprintf('%s — none of [%s] is covered', $short, implode(', ', $concrete));
            }
        }

        self::assertSame(
            [],
            $gaps,
            "An interface tested only by contract, whose implementations carry no #[CoversClass],\n"
            . "looks tested and is not. Give one implementation a covering test, or state why\n"
            . "the contract alone is enough:\n  " . implode("\n  ", $gaps),
        );
    }

    /**
     * @return list<string> Short names of interfaces imported by a CoversNothing test
     */
    private static function interfacesTestedOnlyByContract(string $root): array
    {
        $found = [];

        foreach (self::phpFiles($root, ['tests', 'extensions']) as $file) {
            if (!str_contains($file, '/tests/')) {
                continue;
            }

            $source = (string) file_get_contents($file);

            if (!str_contains($source, '#[CoversNothing]')) {
                continue;
            }

            if (preg_match_all('/^use (Pulsar\\\\[A-Za-z0-9_\\\\]*Interface);/m', $source, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $fqcn) {
                $parts = explode('\\', $fqcn);
                $found[end($parts)] = true;
            }
        }

        return array_map(strval(...), array_keys($found));
    }

    /**
     * @return array<string, list<string>> Interface short name => implementing class names
     */
    private static function concreteImplementations(string $root): array
    {
        $map = [];

        foreach (self::phpFiles($root, ['src', 'extensions']) as $file) {
            $source = (string) file_get_contents($file);

            $pattern = '/^\s*(?:final\s+|readonly\s+)*class\s+([A-Za-z0-9_]+)[^{]*\simplements\s+([^{]+)/m';

            if (preg_match($pattern, $source, $m) !== 1) {
                continue;
            }

            foreach (preg_split('/\s*,\s*/', trim($m[2])) ?: [] as $interface) {
                $short = trim($interface);

                if ($short !== '') {
                    $map[$short][] = $m[1];
                }
            }
        }

        return $map;
    }

    /**
     * @return array<string, true>
     */
    private static function classesDeclaredCovered(string $root): array
    {
        $covered = [];

        foreach (self::phpFiles($root, ['tests', 'extensions']) as $file) {
            if (!str_contains($file, '/tests/')) {
                continue;
            }

            $source = (string) file_get_contents($file);

            if (preg_match_all('/#\[CoversClass\(([A-Za-z0-9_]+)::class\)\]/', $source, $m) === 0) {
                continue;
            }

            foreach ($m[1] as $short) {
                $covered[$short] = true;
            }
        }

        return $covered;
    }

    /**
     * @param list<string> $directories
     * @return list<string>
     */
    private static function phpFiles(string $root, array $directories): array
    {
        $files = [];

        foreach ($directories as $directory) {
            $path = $root . '/' . $directory;

            if (!is_dir($path)) {
                continue;
            }

            $walker = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($walker as $file) {
                if (!$file instanceof SplFileInfo) {
                    continue;
                }

                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = str_replace('\\', '/', $file->getPathname());
                }
            }
        }

        return $files;
    }
}
