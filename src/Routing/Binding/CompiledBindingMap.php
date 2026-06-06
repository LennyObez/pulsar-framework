<?php

declare(strict_types=1);

namespace Pulsar\Routing\Binding;

use NoDiscard;
use Pulsar\Api\Internal;

/**
 * Pre-compiled map of route name to parameter binding metadata.
 *
 * Used in production to skip runtime reflection. Built by the
 * optimize command and loaded from a cached PHP file.
 */
#[Internal(reason: 'Cache artifact; built by optimize command')]
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
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function has(string $routeName, string $parameter): bool
    {
        return isset($this->map[$routeName][$parameter]);
    }

    /**
     * Get binding metadata for a specific route parameter.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
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
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    #[NoDiscard]
    public function all(): array
    {
        return $this->map;
    }

    /**
     * Reconstruct from a serialized cache array.
     *
     * @param array<string, array<string, array{
     *     class?: class-string,
     *     key_name?: string,
     *     key_type?: string,
     *     scoped?: bool,
     *     parent_relation?: string|null,
     *     authz_policy?: string|null,
     *     custom_resolver?: class-string|null,
     * }>> $data
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $map = [];

        foreach ($data as $routeName => $parameters) {
            foreach ($parameters as $paramName => $metaData) {
                $map[$routeName][$paramName] = new BindingMeta(
                    class: $metaData['class'] ?? '',
                    keyName: $metaData['key_name'] ?? 'id',
                    keyType: $metaData['key_type'] ?? 'int',
                    scoped: ($metaData['scoped'] ?? false) === true,
                    parentRelation: $metaData['parent_relation'] ?? null,
                    authzPolicy: $metaData['authz_policy'] ?? null,
                    customResolver: $metaData['custom_resolver'] ?? null,
                );
            }
        }

        return new self($map);
    }

    /**
     * Serialize to an array suitable for caching.
     *
     * @return array<string, array<string, array<string, mixed>>>
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
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
