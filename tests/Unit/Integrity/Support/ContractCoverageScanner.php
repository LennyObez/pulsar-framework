<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_filter;
use function array_keys;
use function array_map;
use function count;
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
 * The rule behind ContractTestCoverageTest, pointed at a root rather than at this one.
 *
 * A contract test proves an interface is implementable. It proves nothing about the
 * classes that ship. `#[CoversNothing]` on an interface test is accurate — an interface
 * has no executable code — but it also makes the interface look tested while its
 * implementations may not be, and that gap is invisible: the suite is green, the coverage
 * report attributes nothing to the test, and nobody is told which shipped class was never
 * exercised.
 *
 * The rule found DbTicketRepository — 334 lines, twelve public methods, no test at all.
 * What it has never done is be observed finding one, because the only tree it could read
 * is the one where that gap is already closed.
 */
final readonly class ContractCoverageScanner
{
    public function __construct(private string $root) {}

    /**
     * Interfaces that look tested and are not.
     *
     * @return list<string>
     */
    public function gaps(): array
    {
        $implementations = $this->concreteImplementations();
        $covered = $this->classesDeclaredCovered();
        $gaps = [];

        foreach ($this->interfacesTestedOnlyByContract() as $short) {
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

        return $gaps;
    }

    /**
     * @return list<string> Short names of interfaces imported by a CoversNothing test
     */
    public function interfacesTestedOnlyByContract(): array
    {
        $found = [];

        foreach ($this->phpFiles(['tests', 'extensions']) as $file) {
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
    public function concreteImplementations(): array
    {
        $map = [];

        foreach ($this->phpFiles(['src', 'extensions']) as $file) {
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
    public function classesDeclaredCovered(): array
    {
        $covered = [];

        foreach ($this->phpFiles(['tests', 'extensions']) as $file) {
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
     *
     * @return list<string>
     */
    public function phpFiles(array $directories): array
    {
        $files = [];

        foreach ($directories as $directory) {
            $path = $this->root . '/' . $directory;

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
