<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\Transform;

use Pulsar\Api\Api;

/**
 * Registry for block transformations.
 *
 * Manages type-to-type transforms and provides lookup for available
 * conversions from any given block type.
 * @api
 */
#[Api(since: '1.0.0')]
final class TransformRegistry
{
    /** @var array<string, list<BlockTransform>> Keyed by fromType */
    private array $transforms = [];

    /**
     * Register a block transform.
     */
    public function register(BlockTransform $transform): void
    {
        $this->transforms[$transform->fromType][] = $transform;
    }

    /**
     * Get all transforms available from a given block type.
     *
     * @return list<BlockTransform>
     */
    public function fromType(string $type): array
    {
        return $this->transforms[$type] ?? [];
    }

    /**
     * Find a specific transform between two types.
     */
    public function find(string $fromType, string $toType): ?BlockTransform
    {
        foreach ($this->transforms[$fromType] ?? [] as $transform) {
            if ($transform->toType === $toType) {
                return $transform;
            }
        }

        return null;
    }

    /**
     * Get the target types a block type can be converted to.
     *
     * @return list<string>
     */
    public function availableTargets(string $fromType): array
    {
        $targets = [];

        foreach ($this->transforms[$fromType] ?? [] as $transform) {
            $targets[] = $transform->toType;
        }

        return $targets;
    }

    /**
     * Apply a transform: convert block data from one type to another.
     *
     * @param array<string, mixed> $data Source block data
     * @return array{type: string, data: array<string, mixed>}|null Null if no transform found
     */
    public function apply(string $fromType, string $toType, array $data): ?array
    {
        $transform = $this->find($fromType, $toType);

        if ($transform === null) {
            return null;
        }

        return [
            'type' => $toType,
            'data' => ($transform->transformer)($data),
        ];
    }
}
