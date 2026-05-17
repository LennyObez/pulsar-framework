<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor\Pattern;

use Pulsar\Api\Api;

/**
 * A reusable layout pattern composed of multiple blocks with pre-filled content.
 *
 * Patterns allow content creators to insert pre-configured block compositions
 * (e.g., Hero + CTA + Testimonials) with a single action.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class BlockPattern
{
    /**
     * @param string $name Unique pattern identifier (e.g., 'hero-cta')
     * @param string $title Human-readable pattern title
     * @param string $description Brief description of the pattern
     * @param string $category Pattern category for grouping (e.g., 'landing', 'content')
     * @param list<array{type: string, data: array<string, mixed>}> $blocks Block definitions with pre-filled data
     * @param list<string> $keywords Search keywords for pattern discovery
     */
    public function __construct(
        public string $name,
        public string $title,
        public string $description,
        public string $category,
        public array $blocks,
        public array $keywords = [],
    ) {}
}
