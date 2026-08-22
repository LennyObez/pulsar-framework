<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ReportsUnknownKeys;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;
use SplFileInfo;

use function class_exists;
use function count;
use function dirname;
use function implode;
use function interface_exists;
use function is_string;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;

/**
 * Drift guard for nested unknown-key reporting.
 *
 * A config DTO reports the keys its own `fromArray()` did not read, and a parent
 * folds its children's reports into its own with a path prefix. That only holds
 * while every child actually reports: a child that does not contributes nothing,
 * silently, and the typo it was meant to catch resolves to a default with no
 * warning anywhere.
 *
 * The gap is invisible by construction — nothing fails, a section is simply not
 * audited — so it is asserted here rather than left to be noticed. This is the
 * same failure mode that let EventConfig, TenancyConfig and five sub-sections of
 * config/security.php go unaudited after the mechanism was already in place.
 *
 * Scope note: this checks children of parents that ALREADY report. A top-level
 * section that reports nothing at all is a different (and louder) gap, covered by
 * the shipped-config drift guard in {@see UnknownKeyAuditTest}.
 */
#[CoversNothing]
final class NestedUnknownKeyCoverageTest extends TestCase
{
    #[Test]
    public function everyChildOfAReportingConfigAlsoReports(): void
    {
        $silent = [];
        $classes = $this->configClasses();

        // Without this, a resolver that silently returns nothing — a moved src/
        // root, a PSR-4 change — turns the whole guard into a test that passes
        // because it checked nothing.
        self::assertGreaterThan(
            50,
            count($classes),
            'the Config class scan collapsed; this guard would pass without checking anything',
        );

        $reportingCount = 0;

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);

            if (!$reflection->implementsInterface(ReportsUnknownKeys::class)) {
                continue;
            }

            ++$reportingCount;

            $constructor = $reflection->getConstructor();

            if ($constructor === null) {
                continue;
            }

            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();

                if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }

                $childName = $type->getName();

                if (!str_ends_with($childName, 'Config') || !class_exists($childName)) {
                    continue;
                }

                if (!new ReflectionClass($childName)->implementsInterface(ReportsUnknownKeys::class)) {
                    $silent[] = sprintf(
                        '%s composes %s, which does not implement ReportsUnknownKeys: '
                        . 'a typo in that sub-section is silently ignored',
                        $reflection->getShortName(),
                        $childName,
                    );
                }
            }
        }

        self::assertGreaterThan(
            20,
            $reportingCount,
            'no reporting DTOs were found, so no parent/child pair was actually verified',
        );
        self::assertSame([], $silent, implode("\n", $silent));
    }

    /**
     * Every `Pulsar\...Config` class under src/, resolved from its path via the
     * framework's own PSR-4 root.
     *
     * @return list<class-string>
     */
    private function configClasses(): array
    {
        $srcRoot = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src';
        self::assertDirectoryExists($srcRoot);

        $classes = [];

        /** @var iterable<string, SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcRoot));

        foreach ($files as $path => $file) {
            if (!is_string($path) || !str_ends_with($path, 'Config.php')) {
                continue;
            }

            $relative = substr($path, strlen($srcRoot) + 1, -4);
            $class = 'Pulsar\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

            if (class_exists($class) || interface_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
