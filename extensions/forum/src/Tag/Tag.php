<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tag;

use Pulsar\Api\Api;

/**
 * Forum tag: a label that can be applied to threads for topic classification.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Tag
{
    /**
     * @param string $id UUIDv7
     * @param string $slug URL-safe identifier (unique)
     * @param string $name Human-readable tag name
     * @param string $description Optional description of the tag's purpose
     * @param int $usageCount Denormalized count of threads using this tag
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public string $description,
        public int $usageCount,
    ) {}

    /**
     * Create a new tag with zero usage.
     */
    public static function create(
        string $id,
        string $slug,
        string $name,
        string $description = '',
    ): self {
        return new self(
            id: $id,
            slug: $slug,
            name: $name,
            description: $description,
            usageCount: 0,
        );
    }

    /**
     * Rename the tag.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function rename(string $name, string $slug): self
    {
        return clone($this, [
            'name' => $name,
            'slug' => $slug,
        ]);
    }

    /**
     * Update the description.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function describe(string $description): self
    {
        return clone($this, [
            'description' => $description,
        ]);
    }

    /**
     * Increment the usage count.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function incrementUsage(): self
    {
        return clone($this, [
            'usageCount' => $this->usageCount + 1,
        ]);
    }

    /**
     * Decrement the usage count (floor at 0).
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function decrementUsage(): self
    {
        return clone($this, [
            'usageCount' => max(0, $this->usageCount - 1),
        ]);
    }
}
