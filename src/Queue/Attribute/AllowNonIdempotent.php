<?php

declare(strict_types=1);

namespace Pulsar\Queue\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Explicitly allows a #[NonIdempotent] job in regulated presets.
 *
 * In regulated environments, jobs marked #[NonIdempotent] are rejected at
 * dispatch time unless this attribute is also present. Both a reason and
 * reviewer identifier are required for audit traceability.
 *
 * When this attribute is present, an audit event is emitted at dispatch time.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Api(since: '1.0.0')]
final readonly class AllowNonIdempotent
{
    public function __construct(
        public string $reason,
        public string $reviewer,
    ) {}
}
