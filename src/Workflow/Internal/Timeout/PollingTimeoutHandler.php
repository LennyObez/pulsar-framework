<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Internal\Timeout;

use DateInterval;
use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Workflow\Storage\WorkflowStorageInterface;
use Pulsar\Workflow\Timeout\TimeoutHandlerInterface;

/**
 * Default timeout handler that stores deadlines in the workflow instance.
 *
 * Timeout detection works via polling: a scheduled command
 * (workflow:check-timeouts) calls findExpiredTimeouts() on the storage
 * layer and takes appropriate action on each expired instance.
 *
 * This implementation requires no external queue infrastructure. Users
 * with queue systems can provide a more efficient implementation via
 * delayed queue messages.
 */
#[Internal(reason: 'Use TimeoutHandlerInterface as the public API')]
final readonly class PollingTimeoutHandler implements TimeoutHandlerInterface
{
    public function __construct(
        private WorkflowStorageInterface $storage,
    ) {}

    #[Override]
    public function scheduleTimeout(string $instanceId, DateInterval $duration): void
    {
        $deadline = new DateTimeImmutable()->add($duration);
        $this->storage->updateTimeout($instanceId, $deadline);
    }

    #[Override]
    public function cancelTimeout(string $instanceId): void
    {
        $this->storage->updateTimeout($instanceId, null);
    }
}
