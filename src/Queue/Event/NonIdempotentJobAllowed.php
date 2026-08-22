<?php

declare(strict_types=1);

namespace Pulsar\Queue\Event;

use Pulsar\Api\Api;

use function time;

/**
 * Audit event emitted when a #[NonIdempotent] job is explicitly allowed
 * via #[AllowNonIdempotent] in a regulated preset.
 *
 * This event provides compliance traceability for non-idempotent job
 * dispatches, recording the justification reason and approving reviewer.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class NonIdempotentJobAllowed
{
    public int $occurredAt;

    public function __construct(
        public string $jobClass,
        public string $reason,
        public string $reviewer,
    ) {
        $this->occurredAt = time();
    }
}
