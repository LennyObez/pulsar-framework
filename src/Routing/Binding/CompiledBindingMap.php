<?php

declare(strict_types=1);

namespace Pulsar\Routing\Binding;

use NoDiscard;
use Pulsar\Api\Internal;

use function is_string;

/**
 * Pre-compiled map of route name to parameter binding metadata.
 *
 * Used in production to skip runtime reflection. Built by the
 * optimize command and loaded from a cached PHP file.
 */
#[Internal(reason: 'Cache artifact — built by optimize command')]
final class CompiledBindingMap
{
    /**
     * @param array<string, array<string, BindingMeta>> $map
     */
    public function __construct(
        private readonly array $map,
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
     * @param array<string, array<string, array<string, mixed>>> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $map = [];

        foreach ($data as $routeName => $parameters) {
            foreach ($parameters as $paramName => $metaData) {
                /** @var array<string, mixed> $metaData */
                /** @var class-string $class */
                $class = isset($metaData['class']) && is_string($metaData['class']) ? $metaData['class'] : '';

                /** @var class-string|null $customResolver */
                $customResolver = isset($metaData['custom_resolver']) && is_string($metaData['custom_resolver'])
                    ? $metaData['custom_resolver']
                    : null;

                $keyName = isset($metaData['key_name']) && is_string($metaData['key_name']) ? $metaData['key_name'] : 'id';
                $keyType = isset($metaData['key_type']) && is_string($metaData['key_type']) ? $metaData['key_type'] : 'int';
                $scoped = isset($metaData['scoped']) && $metaData['scoped'] === true;
                $parentRelation = isset($metaData['parent_relation']) && is_string($metaData['parent_relation'])
                    ? $metaData['parent_relation']
                    : null;
                $authzPolicy = isset($metaData['authz_policy']) && is_string($metaData['authz_policy'])
                    ? $metaData['authz_policy']
                    : null;

                $map[$routeName][$paramName] = new BindingMeta(
                    class: $class,
                    keyName: $keyName,
                    keyType: $keyType,
                    scoped: $scoped,
                    parentRelation: $parentRelation,
                    authzPolicy: $authzPolicy,
                    customResolver: $customResolver,
                );
            }
        }

        return new self($map);
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
                ];

                if ($meta->scoped) {
                    $entry['scoped'] = true;
                }
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
