<?php

declare(strict_types=1);

namespace Pulsar\Tooling\Api;

use Pulsar\Api\Api;
use Pulsar\Api\Internal;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use SplFileInfo;

use function count;

/**
 * Builds the public API snapshot: the single source of truth for the BC gate.
 *
 * Two callers need the exact same result, and they must not be able to drift:
 *
 *   - `tools/api/generate-snapshot.php` writes the committed snapshot.
 *   - `Pulsar\Tests\Unit\Api\PublicApiSnapshotTest` rebuilds it independently and
 *     asserts the committed file still matches the codebase.
 *
 * Those two used to hold byte-identical copies of this logic, kept in sync by a
 * comment asking the next reader to mirror any change. That is not a guarantee:
 * a change applied to the generator alone makes the test regenerate the *old*
 * shape and compare it against the *new* file, which fails for a reason that has
 * nothing to do with the API. A change applied to the test alone is worse — the
 * gate silently stops measuring what the generator records.
 */
final readonly class ApiSnapshotBuilder
{
    public function __construct(private string $sourceDirectory) {}

    /**
     * @return array{api_classes: array<string, array{since: string, methods: list<string>, signatures: array<string, array{params: list<string>, return: string|null, static: bool, inherited_from?: string}>, constants: list<string>}>, internal_classes: list<string>}
     */
    public function build(): array
    {
        $apiClasses = [];
        $internalClasses = [];

        foreach ($this->discoverClasses() as $class) {
            $ref = new ReflectionClass($class);

            $apiAttrs = $ref->getAttributes(Api::class);

            if ($apiAttrs !== []) {
                /** @var Api $apiInstance */
                $apiInstance = $apiAttrs[0]->newInstance();

                $apiClasses[$class] = [
                    'since' => $apiInstance->since,
                    'methods' => $this->annotatedMethods($ref),
                    'signatures' => $this->publicSurface($ref),
                    'constants' => $this->annotatedConstants($ref),
                ];

                continue;
            }

            if ($ref->getAttributes(Internal::class) !== []) {
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
     * Methods this class itself marks `#[Api]`.
     *
     * Declaration-scoped on purpose: this list answers "which members does this
     * class pledge as individually stable", which only its own source can say.
     * An inherited `#[Api]` method is already pledged by the parent that declares
     * it, and reflection would report the parent's attribute here as if this class
     * had written it. The *callable* surface is {@see self::publicSurface()}.
     *
     * @param ReflectionClass<object> $ref
     * @return list<string>
     */
    private function annotatedMethods(ReflectionClass $ref): array
    {
        $methods = [];

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $ref->getName()) {
                continue;
            }

            if ($method->getAttributes(Api::class) !== []) {
                $methods[] = $method->getName();
            }
        }

        sort($methods);

        return $methods;
    }

    /**
     * The full set of public methods callable on this type, inherited included.
     *
     * Recording only self-declared methods left 787 public methods across 64
     * `#[Api]` types outside the gate, and missed two real break shapes:
     *
     *   - A child drops its `extends`/`implements`. Every method it used to offer
     *     through that parent vanishes from its surface. Consumers typed against
     *     the child break; the parent's own entry is untouched, so a
     *     declaration-scoped snapshot shows nothing.
     *   - The parent is not itself `#[Api]` — a vendor interface such as PSR-20's
     *     `ClockInterface`, or an unannotated internal base. Then the inherited
     *     methods appear *nowhere* in the snapshot, and neither their removal nor
     *     a signature change is ever noticed.
     *
     * Methods declared by PHP's own classes are skipped: the engine freezes them,
     * so `Exception::getMessage()` and friends cannot break and would only add
     * hundreds of unreadable entries. Everything else is recorded — a userland
     * parent's signature can change with a refactor, and a vendor parent's can
     * change with a composer constraint.
     *
     * Vendor parents are deliberately in scope even when they dominate the count
     * — `Pulsar\Testing\TestCase` contributes 279 entries inherited from PHPUnit.
     * The line is not volume, it is authorship: whether a consumer keeps those
     * methods depends on a Pulsar decision (keeping the `extends`) and on a Pulsar
     * constraint (the version range in composer.json), so a change on either side
     * is a Pulsar-caused break and belongs in a Pulsar snapshot diff. Carving out
     * the large parents would only hide the breaks that affect the most people —
     * `MiddlewareInterface::process()` reached the gate through exactly this rule,
     * after being absent from it entirely.
     *
     * `inherited_from` names the declaring type so a snapshot diff points straight
     * at the source of a change instead of at the dozens of types feeling it.
     *
     * @param ReflectionClass<object> $ref
     * @return array<string, array{params: list<string>, return: string|null, static: bool, inherited_from?: string}>
     */
    private function publicSurface(ReflectionClass $ref): array
    {
        $signatures = [];

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $declaring = $method->getDeclaringClass();

            if ($declaring->isInternal()) {
                continue;
            }

            $params = [];

            foreach ($method->getParameters() as $param) {
                $paramType = $param->getType();
                $params[] = ($paramType !== null ? (string) $paramType . ' ' : '') . '$' . $param->getName();
            }

            $returnType = $method->getReturnType();

            $signature = [
                'params' => $params,
                'return' => $returnType !== null ? (string) $returnType : null,
                'static' => $method->isStatic(),
            ];

            if ($declaring->getName() !== $ref->getName()) {
                $signature['inherited_from'] = $declaring->getName();
            }

            $signatures[$method->getName()] = $signature;
        }

        ksort($signatures);

        return $signatures;
    }

    /**
     * Constants this class itself marks `#[Api]`.
     *
     * Declaration-scoped for the same reason as {@see self::annotatedMethods()}.
     *
     * @param ReflectionClass<object> $ref
     * @return list<string>
     */
    private function annotatedConstants(ReflectionClass $ref): array
    {
        $constants = [];

        foreach ($ref->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
            if ($constant->getDeclaringClass()->getName() !== $ref->getName()) {
                continue;
            }

            if ($constant->getAttributes(Api::class) !== []) {
                $constants[] = $constant->getName();
            }
        }

        sort($constants);

        return $constants;
    }

    /**
     * Discover every class/interface/enum FQCN under the source directory.
     *
     * @return list<class-string>
     */
    private function discoverClasses(): array
    {
        $classes = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->sourceDirectory),
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

            if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatch) !== 1) {
                continue;
            }

            if (preg_match('/^(?:(?:final|readonly|abstract)\s+)*(?:class|interface|enum)\s+(\w+)/m', $content, $classMatch) !== 1) {
                continue;
            }

            $fqcn = $nsMatch[1] . '\\' . $classMatch[1];

            if (class_exists($fqcn) || interface_exists($fqcn) || enum_exists($fqcn)) {
                /** @var class-string $fqcn */
                $classes[] = $fqcn;
            }
        }

        return $classes;
    }

    /**
     * Count the entries a caller can report after building.
     *
     * @param array{api_classes: array<string, mixed>, internal_classes: list<string>} $snapshot
     * @return array{api: int, internal: int}
     */
    public static function counts(array $snapshot): array
    {
        return [
            'api' => count($snapshot['api_classes']),
            'internal' => count($snapshot['internal_classes']),
        ];
    }
}
