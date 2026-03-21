<?php

declare(strict_types=1);

namespace Pulsar\Queue;

use Pulsar\Api\Api;
use Pulsar\Context\RequestContext;

/**
 * Contextual information passed to a job during execution.
 */
#[Api(since: '1.0.0')]
final readonly class JobContext
{
    public function __construct(
        public string $jobId,
        public string $queue,
        public int $attempt,
        public int $maxAttempts,
        public ?RequestContext $requestContext = null,
    ) {}
}
