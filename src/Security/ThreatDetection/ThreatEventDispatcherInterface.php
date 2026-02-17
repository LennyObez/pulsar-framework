<?php

declare(strict_types=1);

namespace Pulsar\Security\ThreatDetection;

use Pulsar\Api\Api;

/**
 * Dispatches threat events to registered listeners.
 */
#[Api(since: '1.0.0')]
interface ThreatEventDispatcherInterface
{
    public function dispatch(ThreatEvent $event): void;
}
