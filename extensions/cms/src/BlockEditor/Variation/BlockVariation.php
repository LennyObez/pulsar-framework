<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\Variation;

use Pulsar\Api\Api;

/**
 * A variation of an existing block type with pre-configured settings.
 *
 * Variations share the same block type but provide different default
 * configurations and visual identities (e.g., EmbedBlock variations
 * for YouTube, Vimeo, Twitter).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class BlockVariation
{
    /**
     * @param string $name Unique variation identifier (e.g., 'youtube')
     * @param string $blockType Parent block type (e.g., 'embed')
     * @param string $title Human-readable title (e.g., 'YouTube')
     * @param string $description Brief description
     * @param array<string, mixed> $defaults Default data values for this variation
     * @param string|null $icon Optional icon identifier
     */
    public function __construct(
        public string $name,
        public string $blockType,
        public string $title,
        public string $description,
        public array $defaults = [],
        public ?string $icon = null,
    ) {}
}
