<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Api;
use Pulsar\Api\Internal;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use SplFileInfo;

use function dirname;

/**
 * Verifies the committed API snapshot matches the current codebase.
 *
 * If this test fails, regenerate the snapshot:
 *   composer api:snapshot
 */
#[CoversClass(Api::class)]
final class PublicApiSnapshotTest extends TestCase
{
    private const string SNAPSHOT_PATH = __DIR__ . '/../../../tools/api/public-api.snapshot.json';

    #[Test]
    public function snapshotMatchesCurrentCodebase(): void
    {
        $committed = $this->loadCommittedSnapshot();
        $current = $this->generateSnapshot();

        self::assertSame(
            $committed,
            $current,
            "Public API snapshot is stale. Regenerate with: composer api:snapshot\n"
            . 'Then review the diff and commit the updated snapshot.',
        );
    }

    #[Test]
    public function allSnapshotApiClassesHaveApiAttribute(): void
    {
        $committed = $this->loadCommittedSnapshot();

        foreach (array_keys($committed['api_classes']) as $class) {
            self::assertTrue(
                class_exists($class) || interface_exists($class) || enum_exists($class),
                "Snapshot API class {$class} does not exist",
            );

            $ref = new ReflectionClass($class);
            self::assertNotEmpty(
                $ref->getAttributes(Api::class),
                "Snapshot API class {$class} must have #[Api] attribute",
            );
        }
    }

    #[Test]
    public function allSnapshotInternalClassesHaveInternalAttribute(): void
    {
        $committed = $this->loadCommittedSnapshot();

        foreach ($committed['internal_classes'] as $class) {
            self::assertTrue(
                class_exists($class) || interface_exists($class) || enum_exists($class),
                "Snapshot internal class {$class} does not exist",
            );

            $ref = new ReflectionClass($class);
            self::assertNotEmpty(
                $ref->getAttributes(Internal::class),
                "Snapshot internal class {$class} must have #[Internal] attribute",
            );
        }
    }

    /**
     * @return array{api_classes: array<string, array{since: string, methods: list<string>, constants: list<string>}>, internal_classes: list<string>}
     */
    private function loadCommittedSnapshot(): array
    {
        self::assertFileExists(self::SNAPSHOT_PATH, 'Snapshot file missing. Run: composer api:snapshot');

        $json = file_get_contents(self::SNAPSHOT_PATH);
        self::assertIsString($json);

        /** @var array{api_classes: array<string, array{since: string, methods: list<string>, constants: list<string>}>, internal_classes: list<string>} */
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{api_classes: array<string, array{since: string, methods: list<string>, constants: list<string>}>, internal_classes: list<string>}
     */
    private function generateSnapshot(): array
    {
        $srcDir = dirname(__DIR__, 3) . '/src';
        $classes = $this->discoverClasses($srcDir);

        $apiClasses = [];
        $internalClasses = [];

        foreach ($classes as $class) {
            $ref = new ReflectionClass($class);

            $apiAttrs = $ref->getAttributes(Api::class);
            if ($apiAttrs !== []) {
                /** @var Api $apiInstance */
                $apiInstance = $apiAttrs[0]->newInstance();

                $methods = [];
                foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    if ($method->getDeclaringClass()->getName() === $class) {
                        $methodApiAttrs = $method->getAttributes(Api::class);
                        if ($methodApiAttrs !== []) {
                            $methods[] = $method->getName();
                        }
                    }
                }
                sort($methods);

                // Capture full method signatures for API surface tracking.
                // This MUST mirror tools/api/generate-snapshot.php exactly so the
                // committed snapshot and this independent scan stay identical.
                $signatures = [];
                foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    if ($method->getDeclaringClass()->getName() !== $class) {
                        continue;
                    }
                    $params = [];
                    foreach ($method->getParameters() as $param) {
                        $paramType = $param->getType();
                        $params[] = ($paramType !== null ? (string) $paramType . ' ' : '') . '$' . $param->getName();
                    }
                    $returnType = $method->getReturnType();
                    $signatures[$method->getName()] = [
                        'params' => $params,
                        'return' => $returnType !== null ? (string) $returnType : null,
                        'static' => $method->isStatic(),
                    ];
                }
                ksort($signatures);

                $constants = [];
                foreach ($ref->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
                    if ($constant->getDeclaringClass()->getName() === $class) {
                        $constApiAttrs = $constant->getAttributes(Api::class);
                        if ($constApiAttrs !== []) {
                            $constants[] = $constant->getName();
                        }
                    }
                }
                sort($constants);

                $apiClasses[$class] = [
                    'since' => $apiInstance->since,
                    'methods' => $methods,
                    'signatures' => $signatures,
                    'constants' => $constants,
                ];

                continue;
            }

            $internalAttrs = $ref->getAttributes(Internal::class);
            if ($internalAttrs !== []) {
                $internalClasses[] = $class;
            }
        }

        ksort($apiClasses);
        sort($internalClasses);

        return [
            'api_classes' => $apiClasses,
            'internal_classes' => $internalClasses,
        ];
    }

    /**
     * @return list<class-string>
     */
    private function discoverClasses(string $directory): array
    {
        $classes = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if ($content === false) {
                continue;
            }

            if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatch)
                && preg_match('/^(?:(?:final|readonly|abstract)\s+)*(?:class|interface|enum)\s+(\w+)/m', $content, $classMatch)
            ) {
                $fqcn = $nsMatch[1] . '\\' . $classMatch[1];
                if (class_exists($fqcn) || interface_exists($fqcn) || enum_exists($fqcn)) {
                    $classes[] = $fqcn;
                }
            }
        }

        return $classes;
    }
}
