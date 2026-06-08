<?php

declare(strict_types=1);

namespace Pulsar\Api\Resource\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Marks a class as an API resource.
 *
 * API resources are the building blocks for transforming domain entities
 * into API responses. Only fields explicitly marked with {@see Expose}
 * are included in the serialized output (deny-by-default).
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Api(since: '1.0.0')]
final readonly class ApiResource
{
    /**
     * @param string $type The resource type identifier (used in JSON:API, HAL links, etc.)
     * @param int|null $maxFields Override the global max-fields complexity cap for this resource
     */
    public function __construct(
        public string $type = '',
        public ?int $maxFields = null,
    ) {}
}
