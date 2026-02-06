<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Collector;

use function bin2hex;

use Closure;

use function hrtime;

use Pulsar\Api\Internal;
use Pulsar\Queue\Worker;
use Pulsar\Queue\WorkerStatus;
use Pulsar\Studio\Console\Event\ConsoleEvent;
use Pulsar\Studio\Console\Event\Payload\JobPayload;
use Pulsar\Studio\CorrelationContext;
use Pulsar\Studio\FiberScopedContextProvider;
use Random\Engine\Secure;
use Random\RandomException;
use Random\Randomizer;
use Throwable;

/**
 * Worker decorator that instruments job processing for Studio.
 *
 * Wraps processNextJob() to emit JobPayload events with "processing",
 * "completed", or "failed" status for each job. Each job gets a
 * fiber-scoped correlation context with a unique jobId.
 */
#[Internal]
final class InstrumentedWorker implements CollectorInterface
{
    public bool $enabled = true;

    private readonly Randomizer $randomizer;

    /**
     * @param Closure(ConsoleEvent, ?CorrelationContext): void $emit
     */
    public function __construct(
        private readonly Worker $inner,
        private readonly FiberScopedContextProvider $contextProvider,
        private readonly Closure $emit,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    /**
     * Attempt to pop and process the next available job with instrumentation.
     *
     * Emits "processing" on start and "completed" or "failed" on finish.
     *
     * @throws RandomException
     * @throws Throwable
     */
    public function processNextJob(string $queue): bool
    {
        if (!$this->enabled) {
            return $this->inner->processNextJob($queue);
        }

        $existing = $this->contextProvider->current();
        $jobContext = new CorrelationContext(
            requestId: $existing?->requestId,
            traceId: $existing?->traceId,
            spanId: $existing?->spanId,
            jobId: bin2hex($this->randomizer->getBytes(16)),
        );

        $scope = $this->contextProvider->enter($jobContext);
        $startNs = hrtime(true);

        try {
            $processed = $this->inner->processNextJob($queue);

            if ($processed) {
                $durationMs = (float) (hrtime(true) - $startNs) / 1_000_000.0;
                $this->emitJobEvent('completed', $queue, $durationMs, null, $jobContext);
            }

            return $processed;
        } catch (Throwable $e) {
            $durationMs = (float) (hrtime(true) - $startNs) / 1_000_000.0;
            $this->emitJobEvent('failed', $queue, $durationMs, $e->getMessage(), $jobContext);

            throw $e;
        } finally {
            $scope->close();
        }
    }

    /**
     * Delegate run to the inner worker.
     */
    public function run(string $queue): void
    {
        $this->inner->run($queue);
    }

    /**
     * Request a graceful shutdown of the inner worker.
     */
    public function stop(): void
    {
        $this->inner->stop();
    }

    /**
     * Get the inner worker's status.
     */
    public function status(): WorkerStatus
    {
        return $this->inner->status;
    }

    /**
     * Get the underlying worker.
     */
    public function inner(): Worker
    {
        return $this->inner;
    }

    private function emitJobEvent(
        string $status,
        string $queue,
        float $durationMs,
        ?string $errorMessage,
        CorrelationContext $context,
    ): void {
        $event = new JobPayload(
            jobClass: 'unknown',
            status: $status,
            queue: $queue,
            durationMs: $durationMs,
            errorMessage: $errorMessage,
        );

        try {
            ($this->emit)($event, $context);
        } catch (Throwable) {
        }
    }
}
