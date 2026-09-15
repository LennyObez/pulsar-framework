<?php

declare(strict_types=1);

namespace Pulsar\Tooling\Api;

use Pulsar\Api\Api;
use Pulsar\Api\Internal;
use Pulsar\Support\ReflectionTypeName;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use SplFileInfo;

use function array_values;
use function class_exists;
use function count;
use function enum_exists;
use function file_get_contents;
use function interface_exists;
use function is_dir;
use function preg_match;
use function trait_exists;

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
    /** @var list<string> */
    private array $sourceDirectories;

    /**
     * One root per shipped source tree.
     *
     * `src/` alone left 1,306 `#[Api]`-marked classes under `extensions/`
     * outside the gate. They ship inside the same composer package and carry the
     * same attribute, so a consumer typing against them holds the same promise;
     * anything shipped, marked stable, and unwatched is a promise with no guard.
     */
    public function __construct(string ...$sourceDirectories)
    {
        $this->sourceDirectories = array_values($sourceDirectories);
    }

    /**
     * @return array{api_classes: array<string, array{since: string, stability?: string, methods: list<string>, signatures: array<string, array{params: list<string>, return: string|null, static: bool, inherited_from?: string}>, constants: list<string>}>, internal_classes: list<string>}
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

                $entry = [
                    'since' => $apiInstance->since,
                    'methods' => $this->annotatedMethods($ref),
                    'signatures' => $this->publicSurface($ref),
                    'constants' => $this->annotatedConstants($ref),
                ];

                // Recorded only when it departs from the default, which keeps
                // thousands of identical `"stability": "stable"` lines out of the
                // file while leaving an experimental grade visible at a glance —
                // the "clearly marked in the API snapshot" the deprecation policy
                // promises. BcBreakDetector reads a missing key as stable, which
                // is what the attribute itself defaults to.
                if ($apiInstance->stability !== 'stable') {
                    $entry['stability'] = $apiInstance->stability;
                }

                $apiClasses[$class] = $entry;

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
     * A trait widens the filter to protected, and has to. "Callable on this
     * type" does not describe a trait: its members are copied into the consuming
     * class, where a protected one is reachable from every method that class
     * writes. All five `#[Api]`-marked traits here expose protected members and
     * nothing else, so a public-only filter records an empty surface for each —
     * an entry present in the snapshot, watching nothing, which is worse than a
     * visible absence because it reads as coverage. Private members stay out:
     * copied too, but not a contract by any convention.
     *
     * @param ReflectionClass<object> $ref
     * @return array<string, array{params: list<string>, return: string|null, static: bool, inherited_from?: string}>
     */
    private function publicSurface(ReflectionClass $ref): array
    {
        $signatures = [];
        $visibility = $ref->isTrait()
            ? ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED
            : ReflectionMethod::IS_PUBLIC;

        foreach ($ref->getMethods($visibility) as $method) {
            $declaring = $method->getDeclaringClass();

            if ($declaring->isInternal()) {
                continue;
            }

            $params = [];

            foreach ($method->getParameters() as $param) {
                $paramType = $param->getType();
                $params[] = ($paramType !== null ? ReflectionTypeName::of($paramType) . ' ' : '') . '$' . $param->getName();
            }

            $returnType = $method->getReturnType();

            $signature = [
                'params' => $params,
                'return' => $returnType !== null ? ReflectionTypeName::of($returnType) : null,
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
     * Discover every class/interface/enum/trait FQCN under the source roots.
     *
     * Traits are in scope, and were not. The declaration pattern listed
     * class/interface/enum only, so five `#[Api]`-marked traits — the testing
     * concerns and the database helpers an application `use`s directly — were
     * structurally invisible to the snapshot: not merely absent, but incapable
     * of appearing however they were annotated. A trait's public methods land in
     * every consuming class, so renaming one breaks callers exactly as a removed
     * interface method does.
     *
     * @return list<class-string>
     */
    private function discoverClasses(): array
    {
        $classes = [];

        foreach ($this->sourceDirectories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            foreach ($this->phpFilesUnder($directory) as $file) {
                $content = file_get_contents($file->getPathname());

                if ($content === false) {
                    continue;
                }

                if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatch) !== 1) {
                    continue;
                }

                if (preg_match('/^(?:(?:final|readonly|abstract)\s+)*(?:class|interface|enum|trait)\s+(\w+)/m', $content, $classMatch) !== 1) {
                    continue;
                }

                $fqcn = $nsMatch[1] . '\\' . $classMatch[1];

                if (self::typeExists($fqcn)) {
                    /** @var class-string $fqcn */
                    $classes[] = $fqcn;
                }
            }
        }

        return $classes;
    }

    /**
     * Every `.php` file beneath a root, as SplFileInfo.
     *
     * Narrowed with instanceof rather than an inline @var: the iterator is typed
     * as yielding mixed, and asserting a type is not checking it.
     *
     * @return iterable<SplFileInfo>
     */
    private function phpFilesUnder(string $directory): iterable
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                yield $file;
            }
        }
    }

    /**
     * Whether the name resolves to a userland type reflection can open.
     *
     * `class_exists()` answers false for a trait, which is the single line that
     * would have kept traits out of the snapshot even once the declaration
     * pattern matched them. Shared with the test that re-verifies the committed
     * file so the two cannot disagree about what a snapshot entry may be.
     *
     * @phpstan-assert-if-true class-string $name
     * @psalm-assert-if-true class-string $name
     */
    public static function typeExists(string $name): bool
    {
        return class_exists($name) || interface_exists($name) || enum_exists($name) || trait_exists($name);
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
