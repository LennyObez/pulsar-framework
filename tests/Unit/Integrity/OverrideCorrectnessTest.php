<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;

use function class_exists;
use function count;
use function dirname;
use function sprintf;
use function str_replace;
use function strlen;
use function substr;

/**
 * Verify that every method annotated with #[Override] actually
 * overrides a parent class method or implements an interface method.
 *
 * This is a belt-and-suspenders check — PHP 8.3+ enforces this at
 * compile time, but this test catches stale annotations early in CI.
 */
#[CoversNothing]
final class OverrideCorrectnessTest extends TestCase
{
    /** @var list<array{class: class-string, method: string}> */
    private static array $overrideMethods = [];

    public static function setUpBeforeClass(): void
    {
        self::$overrideMethods = self::collectOverrideMethods();
    }

    #[Test]
    public function all_override_methods_actually_override(): void
    {
        $failures = [];

        foreach (self::$overrideMethods as $entry) {
            $class = $entry['class'];
            $method = $entry['method'];

            if (!self::methodExistsOnParentOrInterface($class, $method)) {
                $failures[] = sprintf('%s::%s()', $class, $method);
            }
        }

        self::assertEmpty(
            $failures,
            sprintf(
                "The following methods have #[Override] but do not override a parent/interface method:\n- %s",
                implode("\n- ", $failures),
            ),
        );
    }

    #[Test]
    public function override_coverage_is_not_empty(): void
    {
        self::assertGreaterThan(
            100,
            count(self::$overrideMethods),
            'Expected at least 100 #[Override] annotated methods in the codebase',
        );
    }

    /**
     * @return list<array{class: class-string, method: string}>
     */
    private static function collectOverrideMethods(): array
    {
        $srcDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src';
        $methods = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $className = self::fileToClassName($file->getPathname(), $srcDir);
            if ($className === null || !class_exists($className)) {
                continue;
            }

            /** @var class-string $className */
            $ref = new ReflectionClass($className);
            if ($ref->isAbstract() || $ref->isInterface()) {
                continue;
            }

            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $methodRef) {
                if ($methodRef->getDeclaringClass()->getName() !== $className) {
                    continue;
                }

                $attrs = $methodRef->getAttributes(Override::class);
                if ($attrs !== []) {
                    $methods[] = ['class' => $className, 'method' => $methodRef->getName()];
                }
            }
        }

        return $methods;
    }

    /**
     * @param class-string $class
     */
    private static function methodExistsOnParentOrInterface(string $class, string $method): bool
    {
        $ref = new ReflectionClass($class);

        // Check parent class hierarchy
        $parent = $ref->getParentClass();
        if ($parent !== false && $parent->hasMethod($method)) {
            return true;
        }

        // Check all implemented interfaces
        $interfaces = $ref->getInterfaces();
        foreach ($interfaces as $iface) {
            if ($iface->hasMethod($method)) {
                return true;
            }
        }

        return false;
    }

    private static function fileToClassName(string $filepath, string $srcDir): ?string
    {
        $relative = substr($filepath, strlen($srcDir) + 1);
        $relative = str_replace('.php', '', $relative);
        $relative = str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

        $fqcn = 'Pulsar\\' . $relative;

        return class_exists($fqcn) ? $fqcn : null;
    }
}
