<?php

declare(strict_types=1);

namespace Pulsar\Queue\Attribute;

use Attribute;
use Pulsar\Api\Api;

/**
 * Declares that a job is replay-safe and can be safely retried.
 *
 * When a dedup key template is provided, the queue system uses it to detect
 * duplicate dispatches. If empty, a key is auto-generated from the job class
 * name and a hash of the serialized payload.
 *
 * Jobs marked with this attribute are eligible for automatic retry on failure.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Api(since: '1.0.0')]
final readonly class Idempotent
{
    public function __construct(
        public string $key = '',
    ) {}
}
