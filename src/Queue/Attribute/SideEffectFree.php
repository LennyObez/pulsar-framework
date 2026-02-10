<?php

declare(strict_types=1);

namespace Pulsar\Queue\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Declares that a job performs no mutations (read-only).
 *
 * Side-effect-free jobs are inherently idempotent and are always safe to retry.
 * This provides stronger semantic guarantees than #[Idempotent] — the job
 * produces no side effects beyond reading data.
 *
 * Maps to EffectClassification::ReadOnly.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Api(since: '1.0.0')]
final readonly class SideEffectFree {}
