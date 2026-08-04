<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\CompositionRoots;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function dirname;
use function preg_match;
use function str_contains;
use function str_replace;

/**
 * Composition-root membership must have exactly one definition.
 *
 * It had three: the runtime BoundaryGuard, the static boundary checker, and the wiring
 * checker each carried their own list. They had already drifted, and not only in shape:
 *
 * - `Pulsar\Core\Boot\` was a root for the boundary checker, not for the runtime guard.
 *   A class there passed the static gate and would have been refused when it ran.
 * - The wiring checker was narrower still and reported boot classes as unwired.
 * - The runtime guard prefix-matched its exact class names, silently exempting anything
 *   starting with `Pulsar\Core\Kernel` — `KernelHandler` included.
 *
 * The rule decides which code may cross module boundaries. Three copies of it are three
 * rules, and the checkers disagreeing means one enforces something the others do not.
 */
#[CoversClass(CompositionRoots::class)]
final class CompositionRootsAuthorityTest extends TestCase
{
    #[Test]
    public function noSecondDefinitionExists(): void
    {
        $offenders = [];

        foreach ($this->sources() as $path => $code) {
            // The authority itself is where the constants belong.
            if (str_contains($path, 'src/Api/CompositionRoots.php')) {
                continue;
            }

            if (preg_match('/const\s+array\s+COMPOSITION_ROOTS?(_NAMESPACES)?\s*=/', $code) === 1) {
                $offenders[] = $path;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'COMPOSITION_ROOTS is defined outside its authority. Import '
            . CompositionRoots::class . ' instead — a second copy is a second rule, and '
            . "the three that existed before had already drifted:\n  "
            . implode("\n  ", $offenders),
        );
    }

    /**
     * An exact class name must not act as a prefix.
     *
     * The runtime guard used to match every entry with str_starts_with(), so the exact
     * name `Pulsar\Core\Kernel` exempted `Pulsar\Core\KernelHandler` too — an exemption
     * nobody wrote down and no test would have caught.
     */
    #[Test]
    public function anExactClassNameIsNotTreatedAsAPrefix(): void
    {
        self::assertTrue(CompositionRoots::contains('Pulsar\\Core\\Kernel'));
        self::assertFalse(CompositionRoots::contains('Pulsar\\Core\\KernelHandler'));
        self::assertFalse(CompositionRoots::contains('Pulsar\\Core\\KernelFactory'));
    }

    /**
     * A namespace entry must act as a prefix, which is the whole reason it is separate.
     */
    #[Test]
    public function aNamespaceEntryCoversEverythingBeneathIt(): void
    {
        self::assertTrue(CompositionRoots::contains('Pulsar\\Core\\Wiring\\EarlyHintsWiring'));
        self::assertTrue(CompositionRoots::contains('Pulsar\\Core\\Boot\\ProjectRouteLoader'));

        // Boot was the divergence: a root for the static checker, not for the runtime
        // guard. Both now answer from here, so the two can no longer disagree.
        self::assertTrue(CompositionRoots::contains('Pulsar\\Core\\Boot\\AnythingAtAll'));
    }

    #[Test]
    public function ordinaryClassesAreNotRoots(): void
    {
        foreach (['Pulsar\\Http\\Response', 'Pulsar\\Config\\Environment', 'Pulsar\\Core\\Boot'] as $fqcn) {
            self::assertFalse(CompositionRoots::contains($fqcn), "$fqcn must not be a composition root");
        }
    }

    /**
     * @return array<string, string>
     */
    private function sources(): array
    {
        $root = dirname(__DIR__, 3);
        $sources = [];

        foreach (['src', 'scripts', 'tools'] as $dir) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $path = str_replace('\\', '/', $file->getPathname());
                    $sources[$path] = (string) file_get_contents($path);
                }
            }
        }

        self::assertNotSame([], $sources, 'no sources scanned — the check would pass vacuously');

        return $sources;
    }
}
