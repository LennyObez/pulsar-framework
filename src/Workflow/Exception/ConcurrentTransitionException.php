<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Exception;

use NoDiscard;
use Pulsar\Api\Api;

use function sprintf;

/**
 * Thrown when a compare-and-swap update fails due to a version mismatch.
 *
 * This indicates that another process modified the workflow instance
 * between the read and the attempted write. The caller should retry
 * the operation with a fresh read.
 * @api
 */
#[Api(since: '1.0.0')]
final class ConcurrentTransitionException extends WorkflowException
{
    public function __construct(
        public readonly string $instanceId,
        public readonly int $expectedVersion,
    ) {
        parent::__construct(sprintf(
            'Concurrent transition conflict on workflow instance "%s": expected version %d',
            $instanceId,
            $expectedVersion,
        ));
    }

    #[NoDiscard]
    public static function forInstance(string $instanceId, int $expectedVersion): self
    {
        return new self($instanceId, $expectedVersion);
    }
}
