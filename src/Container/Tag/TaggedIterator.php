<?php

declare(strict_types=1);

namespace Pulsar\Container\Tag;

use Attribute;
use Pulsar\Api\Api;

/**
 * Marks a constructor parameter to receive all services with a given tag.
 *
 * During autowiring, the container resolves all services tagged with the
 * specified tag and injects them as an array.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
#[Api(since: '1.0.0')]
final readonly class TaggedIterator
{
    /**
     * @param string $tag Tag name to collect services for
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public string $tag,
    ) {}
}
