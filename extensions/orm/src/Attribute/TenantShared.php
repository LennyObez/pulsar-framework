<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Marks an entity as shared across all tenants.
 *
 * Tenant scoping filters will NOT be applied to queries on this entity.
 * Use for lookup tables, system configuration, etc.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Api(since: '1.0.0')]
final readonly class TenantShared {}
