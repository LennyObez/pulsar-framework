<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Timeout;

use DateInterval;
use Pulsar\Api\Api;

/**
 * Port for workflow timeout scheduling and cancellation.
 *
 * The engine calls scheduleTimeout() when entering a state with a timeout
 * defined (via 'timeout' metadata on the state definition), and
 * cancelTimeout() when leaving that state.
 *
 * The default PollingTimeoutHandler queries for expired instances via
 * a scheduled command. Users with queue infrastructure may provide their
 * own implementation using delayed queue messages.
 */
#[Api(since: '1.0.0')]
interface TimeoutHandlerInterface
{
    /**
     * Schedule a timeout for a workflow instance.
     *
     * After the given duration elapses, the instance should be flagged as
     * timed out unless cancelTimeout() is called first.
     */
    public function scheduleTimeout(string $instanceId, DateInterval $duration): void;

    /**
     * Cancel a previously scheduled timeout for a workflow instance.
     *
     * Called when the instance leaves a state with a timeout (e.g., via
     * a transition to the next state), making the pending timeout irrelevant.
     */
    public function cancelTimeout(string $instanceId): void;
}
