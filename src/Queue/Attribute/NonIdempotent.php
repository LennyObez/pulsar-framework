<?php

declare(strict_types=1);

namespace Pulsar\Queue\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Declares that a job is NOT replay-safe.
 *
 * The worker will NOT auto-retry jobs marked with this attribute on failure.
 * A mandatory reason must explain why the job cannot be made idempotent.
 *
 * In regulated presets, jobs with this attribute are rejected at dispatch
 * time unless an #[AllowNonIdempotent] attribute is also present on the class.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Api(since: '1.0.0')]
final readonly class NonIdempotent
{
    public function __construct(
        public string $reason,
    ) {}
}
