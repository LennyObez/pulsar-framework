<?php

declare(strict_types=1);

namespace Pulsar\Queue\Event;

use Pulsar\Api\Api;

/**
 * Emitted when a dead-letter queue job is inspected.
 */
#[Api(since: '1.0.0')]
final readonly class DlqJobInspected
{
    public function __construct(
        public string $jobId,
        public string $actorId,
        public string $correlationId,
        public int $timestamp,
    ) {}
}
