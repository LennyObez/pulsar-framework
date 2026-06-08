<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Marks an entity as tenant-scoped.
 *
 * Queries on this entity will automatically filter by the current tenant
 * and inserts will automatically set the tenant column.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Api(since: '1.0.0')]
final readonly class TenantScoped
{
    public function __construct(
        public ?string $column = null,
    ) {}
}
