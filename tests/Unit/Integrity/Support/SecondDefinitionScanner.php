<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function file_get_contents;
use function is_dir;
use function preg_match;
use function str_contains;
use function str_replace;

/**
 * The rule behind CompositionRootsAuthorityTest, pointed at a root rather than at this one.
 *
 * Composition-root membership decides which code may cross module boundaries, and it had
 * three definitions: the runtime BoundaryGuard, the static boundary checker and the
 * wiring checker each carried their own list. They had already drifted — `Pulsar\Core\Boot\`
 * was a root for one and not the others, so a class there passed the static gate and
 * would have been refused when it ran.
 *
 * One definition is the repair; this rule is what keeps it at one. Keeping it at one is a
 * claim, and the claim had never been tested against a repository that contains a second
 * copy, because the only repository the scan could read was this one — where the second
 * copies had already been deleted.
 */
final readonly class SecondDefinitionScanner
{
    /** Where the constants belong, and the only file allowed to declare them. */
    public const string AUTHORITY = 'src/Api/CompositionRoots.php';

    /** @var list<string> Trees that could plausibly hold a copy of the rule. */
    private const array TREES = ['src', 'scripts', 'tools'];

    public function __construct(private string $root) {}

    /**
     * Files declaring the composition-root list anywhere but the authority.
     *
     * @return list<string>
     */
    public function offenders(): array
    {
        $offenders = [];

        foreach ($this->sources() as $path => $code) {
            if (str_contains($path, self::AUTHORITY)) {
                continue;
            }

            if (preg_match('/const\s+array\s+COMPOSITION_ROOTS?(_NAMESPACES)?\s*=/', $code) === 1) {
                $offenders[] = $path;
            }
        }

        return $offenders;
    }

    /**
     * @return array<string, string> path => contents
     */
    public function sources(): array
    {
        $sources = [];

        foreach (self::TREES as $directory) {
            $path = $this->root . '/' . $directory;

            if (!is_dir($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $found = str_replace('\\', '/', $file->getPathname());
                    $sources[$found] = (string) file_get_contents($found);
                }
            }
        }

        return $sources;
    }
}
