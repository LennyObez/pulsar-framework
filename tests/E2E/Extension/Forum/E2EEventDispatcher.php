<?php

declare(strict_types=1);

namespace Pulsar\Tests\E2E\Extension\Forum;

use Override;
use Psr\EventDispatcher\EventDispatcherInterface;
use Pulsar\Event\EventDispatcherInterface as PulsarEventDispatcherInterface;
use Pulsar\Event\EventEnvelope;

/**
 * @internal Stub event dispatcher for E2E tests.
 */
final class E2EEventDispatcher implements EventDispatcherInterface, PulsarEventDispatcherInterface
{
    /** @var list<object> */
    private array $dispatched = [];

    #[Override]
    public function dispatch(object $event): object
    {
        $this->dispatched[] = $event;

        return $event;
    }

    #[Override]
    public function dispatchEnvelope(EventEnvelope $envelope): EventEnvelope
    {
        $this->dispatched[] = $envelope;

        return $envelope;
    }

    /** @return list<object> */
    public function getDispatched(): array
    {
        return $this->dispatched;
    }
}
