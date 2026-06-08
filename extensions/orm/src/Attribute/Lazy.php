<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Marks a relation property for opt-in lazy loading.
 *
 * When present, the relation is not loaded eagerly. Instead a
 * LazyRelationProxy is assigned that loads the related entities
 * on first access.
 *
 * Explicit FetchPlan declarations override this attribute:
 * if a relation is both #[Lazy] and included in a FetchPlan,
 * the FetchPlan wins and the relation is loaded eagerly.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
#[Api(since: '1.0.0')]
final readonly class Lazy {}
