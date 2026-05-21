<?php

declare(strict_types=1);

namespace Pulsar\Container\Tag;

use Attribute;
use Pulsar\Api\Api;

/**
 * Marks a service class as tagged with a specific tag name.
 *
 * This attribute is scanned by the AutoTagPass compiler pass to
 * automatically populate service definition tags.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
#[Api(since: '1.0.0')]
final readonly class Tag
{
    /**
     * @param string $name Tag name (e.g. 'event.listener')
     * @param int $priority Sorting priority (higher = earlier)
     * @param array<string, mixed> $attributes Arbitrary tag metadata
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public string $name,
        public int $priority = 0,
        public array $attributes = [],
    ) {}
}
