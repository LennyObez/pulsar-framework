<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Collector;

use Closure;
use Pulsar\Api\Internal;
use Pulsar\Queue\QueueManager;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\Payload\JobPayload;
use Pulsar\Observability\Context\CorrelationContext;
use Pulsar\Extension\Studio\FiberScopedContextProvider;
use Throwable;

/**
 * QueueManager decorator that instruments job dispatch for Studio.
 *
 * Emits a JobPayload with status "queued" on each successful dispatch,
 * capturing the queue name and job class for the Studio event timeline.
 */
#[Internal]
final class InstrumentedQueueManager implements CollectorInterface
{
    public bool $enabled = true;

    /**
     * @param Closure(ConsoleEvent, ?CorrelationContext): void $emit
     */
    public function __construct(
        private readonly QueueManager $inner,
        private readonly FiberScopedContextProvider $contextProvider,
        private readonly Closure $emit,
    ) {}

    /**
     * Dispatch a job and emit a Studio event.
     */
    public function dispatch(
        string $jobClass,
        string $payload,
        ?string $queue = null,
        int $delay = 0,
    ): string {
        $jobId = $this->inner->dispatch($jobClass, $payload, $queue, $delay);

        if ($this->enabled) {
            $this->emitJobEvent($jobClass, $queue);
        }

        return $jobId;
    }

    /**
     * Get the number of pending jobs on the given queue.
     */
    public function size(?string $queue = null): int
    {
        return $this->inner->size($queue);
    }

    /**
     * Get the underlying queue manager.
     */
    public function inner(): QueueManager
    {
        return $this->inner;
    }

    private function emitJobEvent(string $jobClass, ?string $queue): void
    {
        $event = new JobPayload(
            jobClass: $jobClass,
            status: 'queued',
            queue: $queue,
        );

        $context = $this->contextProvider->current();

        try {
            ($this->emit)($event, $context);
        } catch (Throwable) {
        }
    }
}
