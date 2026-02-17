<?php

declare(strict_types=1);

/**
 * Public API Snapshot Generator
 *
 * Scans src/ for classes annotated with #[Api] or #[Internal] and produces
 * a deterministic JSON snapshot at tools/api/public-api.snapshot.json.
 *
 * Usage: php tools/api/generate-snapshot.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Pulsar\Api\Api;
use Pulsar\Api\Internal;

$srcDir = __DIR__ . '/../../src';
$outputPath = __DIR__ . '/public-api.snapshot.json';

/**
 * Discover all class/interface/enum FQCNs in a directory.
 *
 * @return list<class-string>
 */
function discoverClasses(string $directory): array
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

/**
 * Build the snapshot data structure.
 *
 * @param list<class-string> $classes
 * @return array{api_classes: array<string, array{since: string, methods: list<string>, constants: list<string>}>, internal_classes: list<string>}
 */
function buildSnapshot(array $classes): array
{
    $apiClasses = [];
    $internalClasses = [];

    foreach ($classes as $class) {
        $ref = new ReflectionClass($class);

        // Check class-level #[Api]
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

            // Capture full method signatures for API surface tracking
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

        // Check class-level #[Internal]
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

$classes = discoverClasses($srcDir);
$snapshot = buildSnapshot($classes);

$json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
file_put_contents($outputPath, $json);

$apiCount = count($snapshot['api_classes']);
$internalCount = count($snapshot['internal_classes']);

echo "Snapshot generated: {$outputPath}\n";
echo "  API classes: {$apiCount}\n";
echo "  Internal classes: {$internalCount}\n";
