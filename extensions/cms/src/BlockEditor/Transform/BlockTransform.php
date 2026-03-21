<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\Transform;

use Closure;
use Pulsar\Api\Api;

/**
 * Defines a transformation between two block types.
 *
 * Transforms enable converting one block type to another while preserving
 * content (e.g., Heading to Paragraph, List to Paragraph).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class BlockTransform
{
    /**
     * @param string $fromType Source block type
     * @param string $toType Target block type
     * @param Closure(array<string, mixed>): array<string, mixed> $transformer Function to convert data
     */
    public function __construct(
        public string $fromType,
        public string $toType,
        public Closure $transformer,
    ) {}
}
