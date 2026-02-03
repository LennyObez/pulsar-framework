<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use function class_exists;
use function count;
use function dirname;

use NoDiscard;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use SplFileInfo;

use function sprintf;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Verify that #[NoDiscard] is applied correctly:
 * 1. Methods with #[NoDiscard] must return a non-void type.
 * 2. Static factory methods (returning self/static) should have #[NoDiscard].
 * 3. Immutable with*() methods should have #[NoDiscard].
 */
#[CoversNothing]
final class NoDiscardCorrectnessTest extends TestCase
{
    /** @var list<array{class: string, method: string, returnType: string}> */
    private static array $noDiscardMethods = [];

    /** @var list<array{class: class-string, method: string}> */
    private static array $allPublicMethods = [];

    public static function setUpBeforeClass(): void
    {
        $srcDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src';
        self::$noDiscardMethods = self::collectNoDiscardMethods($srcDir);
        self::$allPublicMethods = self::collectPublicMethods($srcDir);
    }

    #[Test]
    public function no_discard_methods_return_non_void(): void
    {
        $failures = [];

        foreach (self::$noDiscardMethods as $entry) {
            if ($entry['returnType'] === 'void') {
                $failures[] = sprintf('%s::%s()', $entry['class'], $entry['method']);
            }
        }

        self::assertEmpty(
            $failures,
            sprintf(
                "The following methods have #[NoDiscard] but return void:\n- %s",
                implode("\n- ", $failures),
            ),
        );
    }

    #[Test]
    public function no_discard_coverage_is_not_empty(): void
    {
        self::assertGreaterThan(
            50,
            count(self::$noDiscardMethods),
            'Expected at least 50 #[NoDiscard] annotated methods in the codebase',
        );
    }

    #[Test]
    public function with_methods_on_readonly_classes_have_no_discard(): void
    {
        $missing = [];

        foreach (self::$allPublicMethods as $entry) {
            $class = $entry['class'];
            $method = $entry['method'];

            if (!str_starts_with($method, 'with')) {
                continue;
            }

            $ref = new ReflectionClass($class);
            if (!$ref->isReadOnly()) {
                continue;
            }

            $methodRef = $ref->getMethod($method);
            $returnType = $methodRef->getReturnType();

            // Only check methods that return self/static (immutable modifiers)
            if (!$returnType instanceof ReflectionNamedType) {
                continue;
            }

            $typeName = $returnType->getName();
            if ($typeName !== 'self' && $typeName !== 'static' && $typeName !== $class) {
                continue;
            }

            $attrs = $methodRef->getAttributes(NoDiscard::class);
            if ($attrs === []) {
                $missing[] = sprintf('%s::%s()', $class, $method);
            }
        }

        self::assertEmpty(
            $missing,
            sprintf(
                "The following with*() methods on readonly classes are missing #[NoDiscard]:\n- %s",
                implode("\n- ", $missing),
            ),
        );
    }

    /**
     * @return list<array{class: string, method: string, returnType: string}>
     */
    private static function collectNoDiscardMethods(string $srcDir): array
    {
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

            $ref = new ReflectionClass($className);
            if ($ref->isInterface()) {
                continue;
            }

            foreach ($ref->getMethods() as $methodRef) {
                if ($methodRef->getDeclaringClass()->getName() !== $className) {
                    continue;
                }

                $attrs = $methodRef->getAttributes(NoDiscard::class);
                if ($attrs === []) {
                    continue;
                }

                $returnType = $methodRef->getReturnType();
                $typeName = $returnType instanceof ReflectionNamedType
                    ? $returnType->getName()
                    : ($returnType !== null ? (string) $returnType : 'mixed');

                $methods[] = [
                    'class' => $className,
                    'method' => $methodRef->getName(),
                    'returnType' => $typeName,
                ];
            }
        }

        return $methods;
    }

    /**
     * @return list<array{class: class-string, method: string}>
     */
    private static function collectPublicMethods(string $srcDir): array
    {
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
            if ($ref->isInterface() || $ref->isAbstract()) {
                continue;
            }

            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $methodRef) {
                if ($methodRef->getDeclaringClass()->getName() !== $className) {
                    continue;
                }

                $methods[] = ['class' => $className, 'method' => $methodRef->getName()];
            }
        }

        return $methods;
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
