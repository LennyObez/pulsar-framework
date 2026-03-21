<?php

declare(strict_types=1);

namespace Pulsar\Event;

use Psr\EventDispatcher\EventDispatcherInterface as PsrEventDispatcherInterface;
use Pulsar\Api\Api;

/**
 * Pulsar event dispatcher extending PSR-14 with envelope-aware dispatch.
 * @api
 */
#[Api(since: '1.0.0')]
interface EventDispatcherInterface extends PsrEventDispatcherInterface
{
    /**
     * Dispatch an event wrapped in an envelope.
     *
     * Computes scope (Internal vs CrossModule) based on listener metadata
     * and stamps the envelope accordingly before dispatching.
     */
    public function dispatchEnvelope(EventEnvelope $envelope): EventEnvelope;
}
