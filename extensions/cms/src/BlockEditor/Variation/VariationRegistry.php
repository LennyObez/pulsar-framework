<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\Variation;

use Pulsar\Api\Api;

/**
 * Registry for block variations.
 */
#[Api(since: '1.0.0')]
final class VariationRegistry
{
    /** @var array<string, list<BlockVariation>> Keyed by blockType */
    private array $variations = [];

    /**
     * Register a block variation.
     */
    public function register(BlockVariation $variation): void
    {
        $this->variations[$variation->blockType][] = $variation;
    }

    /**
     * Get all variations for a block type.
     *
     * @return list<BlockVariation>
     */
    public function forType(string $blockType): array
    {
        return $this->variations[$blockType] ?? [];
    }

    /**
     * Find a specific variation by name within a block type.
     */
    public function find(string $blockType, string $variationName): ?BlockVariation
    {
        foreach ($this->variations[$blockType] ?? [] as $variation) {
            if ($variation->name === $variationName) {
                return $variation;
            }
        }

        return null;
    }

    /**
     * Get all registered variations across all types.
     *
     * @return list<BlockVariation>
     */
    public function all(): array
    {
        $all = [];

        foreach ($this->variations as $variations) {
            $all = [...$all, ...$variations];
        }

        return $all;
    }
}
