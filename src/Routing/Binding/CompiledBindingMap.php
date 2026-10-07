<?php

declare(strict_types=1);

namespace Pulsar\Routing\Binding;

use NoDiscard;
use Pulsar\Api\Internal;

/**
 * Pre-compiled map of route name to parameter binding metadata.
 *
 * Intended to skip runtime reflection in production. **Nothing builds one
 * today** — `pulsar optimize` caches routes, config and container hints and
 * writes no binding map, and no other producer ships — so this class is only
 * reached when a host binds an instance it assembled itself.
 * {@see BindingResolver} uses one whenever it is bound and reflects the
 * controller signature when it is not.
 *
 * Entries here are *declarations*, not decisions: the route path still orders
 * them and still fills in the parent of a contained binding. What a map entry
 * does carry authoritatively is its {@see BindingScope} — including
 * {@see BindingScope::Root}, which is how a compiled map states that a
 * parameter inside a nested path is global on purpose. The serialized form
 * spells the scope out for that reason; an absent `scope` key is the
 * "let the path decide" default, and cannot be confused with a decision to
 * skip the containment check.
 */
#[Internal(reason: 'Cache artifact; no producer ships today, bind one yourself to use it')]
final readonly class CompiledBindingMap
{
    /**
     * @param array<string, array<string, BindingMeta>> $map
     */
    public function __construct(
        private array $map,
    ) {}

    /**
     * Check if a binding exists for the given route and parameter.
     */
    public function has(string $routeName, string $parameter): bool
    {
        return isset($this->map[$routeName][$parameter]);
    }

    /**
     * Get binding metadata for a specific route parameter.
     */
    #[NoDiscard]
    public function get(string $routeName, string $parameter): ?BindingMeta
    {
        return $this->map[$routeName][$parameter] ?? null;
    }

    /**
     * Get all binding metadata for a route.
     *
     * @return array<string, BindingMeta>
     */
    #[NoDiscard]
    public function getForRoute(string $routeName): array
    {
        return $this->map[$routeName] ?? [];
    }

    /**
     * Get the full compiled map.
     *
     * @return array<string, array<string, BindingMeta>>
     */
    #[NoDiscard]
    public function all(): array
    {
        return $this->map;
    }

    /**
     * Reconstruct from a serialized cache array.
     *
     * An unreadable `scope` value is not defaulted to something permissive —
     * a cache file written by a newer build, or edited by hand, must not be
     * able to turn a containment check off by naming a scope this build does
     * not know. Anything unrecognized falls back to {@see BindingScope::Path},
     * which leaves the route path in charge.
     *
     * @param array<string, array<string, array{
     *     class?: class-string,
     *     key_name?: string,
     *     key_type?: string,
     *     scope?: string,
     *     scoped?: bool,
     *     parent_relation?: string|null,
     *     authz_policy?: string|null,
     *     custom_resolver?: class-string|null,
     * }>> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $map = [];

        foreach ($data as $routeName => $parameters) {
            foreach ($parameters as $paramName => $metaData) {
                $class = $metaData['class'] ?? '';
                if ($class === '') {
                    // A binding without a target class cannot resolve anything; skip.
                    continue;
                }

                $scope = self::scopeFrom(
                    $metaData['scope'] ?? null,
                    ($metaData['scoped'] ?? false) === true,
                    $metaData['parent_relation'] ?? null,
                );

                $map[$routeName][$paramName] = new BindingMeta(
                    class: $class,
                    keyName: $metaData['key_name'] ?? 'id',
                    keyType: $metaData['key_type'] ?? 'int',
                    scope: $scope,
                    parentRelation: $scope === BindingScope::Contained
                        ? ($metaData['parent_relation'] ?? null)
                        : null,
                    authzPolicy: $metaData['authz_policy'] ?? null,
                    customResolver: $metaData['custom_resolver'] ?? null,
                );
            }
        }

        return new self($map);
    }

    /**
     * Read the declared scope out of one serialized entry.
     *
     * `$legacyScoped` is the shape maps were written in before the scope became
     * a closed type. It still means "resolve through the parent", and it still
     * needs a relation to do it with; without one there is nothing to declare
     * and the path decides.
     */
    private static function scopeFrom(?string $declared, bool $legacyScoped, ?string $relation): BindingScope
    {
        if ($declared !== null) {
            $scope = BindingScope::tryFrom($declared) ?? BindingScope::Path;

            return $scope === BindingScope::Contained && $relation === null ? BindingScope::Path : $scope;
        }

        return $legacyScoped && $relation !== null ? BindingScope::Contained : BindingScope::Path;
    }

    /**
     * Serialize to an array suitable for caching.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    #[NoDiscard]
    public function toArray(): array
    {
        $data = [];

        foreach ($this->map as $routeName => $parameters) {
            foreach ($parameters as $paramName => $meta) {
                $entry = [
                    'class' => $meta->class,
                    'key_name' => $meta->keyName,
                    'key_type' => $meta->keyType,
                    // Always written, including the default: a reader has to be
                    // able to tell "nothing was declared" from "declared root",
                    // and an absent key cannot say both.
                    'scope' => $meta->scope->value,
                ];

                if ($meta->parentRelation !== null) {
                    $entry['parent_relation'] = $meta->parentRelation;
                }
                if ($meta->authzPolicy !== null) {
                    $entry['authz_policy'] = $meta->authzPolicy;
                }
                if ($meta->customResolver !== null) {
                    $entry['custom_resolver'] = $meta->customResolver;
                }

                $data[$routeName][$paramName] = $entry;
            }
        }

        return $data;
    }
}
