<?php

declare(strict_types=1);

namespace Pulsar\Extensibility\Internal;

use NoDiscard;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;

use function array_key_exists;
use function class_exists;
use function implode;
use function in_array;
use function interface_exists;
use function sprintf;
use function strtolower;

/**
 * Audits {@see ServiceRestrictionMap}'s safe list, by reflection, in CI.
 *
 * One question and only one: can a caller holding this type reach something
 * that hands out services, through its public API? An entry earns its place on
 * the safe list by the answer being no, and `SandboxReachAnalyzerTest` fails the
 * suite when a listed type grows a `->container()` accessor.
 *
 * ## What this class used to also do, and why it does not any more
 *
 * It answered a second question — "if the container autowires this class, will a
 * {@see SandboxReach} root end up inside it?" — asked at boot whenever an
 * extension bound a class NAME and whenever a service provider was instantiated.
 * That was a FILTER: it inspected a name and predicted what the real container
 * would put behind it. Two of its properties were exploited, and both are
 * inherent to the shape rather than to the implementation.
 *
 *  - It walked four constructors deep, so inserting one more collaborator in
 *    the chain put the container outside the walk and the answer was "no reach
 *    found".
 *  - Its caller opened with `if (!class_exists($className)) { return; }`,
 *    because a name that does not resolve cannot be reflected. An extension
 *    chooses when its own classes are defined, so it could bind first and
 *    declare afterwards, and the class the container eventually built was never
 *    the class that was vetted.
 *
 * Neither is patched here, because the question is no longer asked. A class an
 * extension names is now BUILT BY {@see ScopedContainerProxy::construct()}: the
 * proxy resolves each constructor parameter through its own `get()`, which
 * applies the tier, the restriction map and the value guard to every dependency,
 * and does the same for that dependency's dependencies, with no depth limit and
 * nothing to predict. A prediction that runs before the class exists is replaced
 * by a construction that happens after it does.
 *
 * ## Why the surface walk survives the same argument
 *
 * It is not a runtime filter and nothing is admitted on its say-so. It runs in
 * CI over a hand-written list of about a dozen ids and reports a type whose
 * DECLARED surface leads somewhere it should not — the early warning that would
 * have failed on `ContainerInterface` being safe-listed. What actually holds at
 * runtime is {@see ScopedContainerProxy::contain()}, which inspects the object
 * that arrives whatever its declared type said. This is the cheap check that
 * catches the mistake before it ships, not the check the guarantee rests on.
 *
 * It is also unbounded, deliberately. The walk terminates on `$visited` — every
 * type is expanded at most once — so a depth limit bought nothing but the
 * "one more hop" bypass that cost the constructor walk its usefulness.
 *
 * `mixed`, `object` and untyped surfaces are not treated as reach: they carry no
 * type-level claim to prove or disprove, and refusing every `get(): mixed` would
 * refuse most of the framework. `contain()` covers what flows through them.
 *
 * @internal Not part of the public API
 */
final readonly class SandboxReachAnalyzer
{
    /**
     * The path by which a holder of `$type` could reach a reach-capable root
     * through public methods and properties, or null when it cannot.
     *
     * Accepts any string rather than a `class-string`: a name that does not
     * resolve in this build is a legitimate question. Its answer here is "no
     * path found", which is why callers that pass ids from a configuration map
     * must check that the type EXISTS before reading anything into a null —
     * `ServiceRestrictionMapTest` does exactly that, after five map entries
     * naming absent types proved themselves inert by being absent.
     */
    #[NoDiscard]
    public static function surfaceReach(string $type): ?string
    {
        $visited = [];

        return self::walkSurface($type, $visited, [$type]);
    }

    /**
     * @param array<string, true> $visited
     * @param list<string> $path
     */
    private static function walkSurface(string $type, array &$visited, array $path): ?string
    {
        $root = SandboxReach::rootFor($type);

        if ($root !== null) {
            return self::describe($path, $root);
        }

        if (array_key_exists($type, $visited)) {
            return null;
        }

        if (!class_exists($type) && !interface_exists($type)) {
            return null;
        }

        $visited[$type] = true;
        $reflection = new ReflectionClass($type);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()) {
                continue;
            }

            foreach (self::namedTypes($method->getReturnType()) as $candidate) {
                $found = self::walkSurface(
                    $candidate,
                    $visited,
                    [...$path, sprintf('%s(): %s', $method->getName(), $candidate)],
                );

                if ($found !== null) {
                    return $found;
                }
            }
        }

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            foreach (self::namedTypes($property->getType()) as $candidate) {
                $found = self::walkSurface(
                    $candidate,
                    $visited,
                    [...$path, sprintf('$%s: %s', $property->getName(), $candidate)],
                );

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * All named class types inside a (possibly union or intersection) type.
     *
     * Built-ins, `mixed`, `object`, `self`, `static` and `parent` are excluded:
     * they name no concrete surface to follow, and are covered at runtime by
     * {@see ScopedContainerProxy}'s value guard instead.
     *
     * @return list<string>
     */
    private static function namedTypes(?ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            if ($type->isBuiltin()) {
                return [];
            }

            $name = $type->getName();

            // Relative and unbounded type names resolve to whatever the call
            // site happens to be, so there is no concrete surface to follow.
            // Compared case-insensitively because PHP accepts `SELF` and
            // `Static` in a type position and reflection reports them verbatim.
            if (in_array(strtolower($name), ['self', 'static', 'parent', 'object'], true)) {
                return [];
            }

            return [$name];
        }

        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            $names = [];

            foreach ($type->getTypes() as $member) {
                $names = [...$names, ...self::namedTypes($member)];
            }

            return $names;
        }

        return [];
    }

    /**
     * @param list<string> $path
     */
    private static function describe(array $path, string $root): string
    {
        return implode(' -> ', $path) . sprintf(' [reach-capable root: %s]', $root);
    }
}
