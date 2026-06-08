<?php

declare(strict_types=1);

namespace Pulsar\Container;

use Pulsar\Api\Internal;

/**
 * Immutable tag metadata attached to a service definition.
 */
#[Internal]
final readonly class TagDefinition
{
    /**
     * @param string $name Tag name (e.g. 'event.listener')
     * @param int $priority Sorting priority (higher = earlier, default 0)
     * @param array<string, mixed> $attributes Arbitrary tag metadata
     */
    public function __construct(
        public string $name,
        public int $priority = 0,
        public array $attributes = [],
    ) {}
}
