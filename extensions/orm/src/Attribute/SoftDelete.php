<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Enables soft delete support for an entity.
 *
 * Soft-deleted entities are excluded from queries by default
 * unless explicitly included.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Api(since: '1.0.0')]
final readonly class SoftDelete
{
    public function __construct(
        public string $column = 'deleted_at',
    ) {}
}
