<?php

declare(strict_types=1);

namespace Pulsar\Queue;

use function json_encode;

use const JSON_THROW_ON_ERROR;

use Pulsar\Api\Api;
use Pulsar\Config\QueueConfig;
use Pulsar\Config\QueueDriverType;
use Pulsar\Context\ContextPropagator;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Queue\Driver\InMemoryDriver;
use Pulsar\Queue\Driver\SyncDriver;
use Pulsar\Queue\Exception\QueueException;

/**
 * Central orchestrator for dispatching jobs and querying queue state.
 *
 * Lazily resolves the queue driver from configuration when no explicit
 * driver is injected via the constructor. When a RequestContextHolder
 * is available, automatically propagates context into job payloads.
 */
#[Api(since: '1.0.0')]
final class QueueManager
{
    private ?QueueDriverInterface $resolvedDriver;

    public function __construct(
        private readonly QueueConfig $config,
        ?QueueDriverInterface $driver = null,
        private readonly ?RequestContextHolder $contextHolder = null,
    ) {
        $this->resolvedDriver = $driver;
    }

    /**
     * Dispatch a job onto the queue.
     *
     * @param string      $jobClass Fully-qualified class name of the job.
     * @param string      $payload  Serialized job payload.
     * @param string|null $queue    Target queue name (defaults to config default).
     * @param int         $delay    Delay in seconds before the job becomes available.
     *
     * @return string The unique identifier assigned to the dispatched job.
     */
    public function dispatch(
        string $jobClass,
        string $payload,
        ?string $queue = null,
        int $delay = 0,
    ): string {
        $targetQueue = $queue ?? $this->config->defaultQueue;
        $enrichedPayload = $this->injectContext($payload);

        return $this->driver()->push($targetQueue, $jobClass, $enrichedPayload, $delay);
    }

    /**
     * Get the number of pending jobs on the given queue.
     */
    public function size(?string $queue = null): int
    {
        $targetQueue = $queue ?? $this->config->defaultQueue;

        return $this->driver()->size($targetQueue);
    }

    /**
     * Get the active queue driver, lazily resolving from config if needed.
     *
     * @throws QueueException When the configured driver type is not supported.
     */
    public function driver(): QueueDriverInterface
    {
        if ($this->resolvedDriver !== null) {
            return $this->resolvedDriver;
        }

        $this->resolvedDriver = match ($this->config->driver) {
            QueueDriverType::Sync => new SyncDriver(),
            QueueDriverType::Memory => new InMemoryDriver(),
            QueueDriverType::Database => throw QueueException::driverNotConfigured(
                'database (requires ConnectionManagerInterface injection)',
            ),
        };

        return $this->resolvedDriver;
    }

    /**
     * Inject request context into payload if holder is available.
     *
     * Wraps the original payload in a JSON envelope containing context metadata.
     * The Worker extracts this envelope to restore context before job execution.
     */
    private function injectContext(string $payload): string
    {
        $requestContext = $this->contextHolder?->tryGet();

        if ($requestContext === null) {
            return $payload;
        }

        $carrier = [];
        ContextPropagator::inject($requestContext, $carrier);

        return json_encode([
            '_ctx' => $carrier,
            '_payload' => $payload,
        ], JSON_THROW_ON_ERROR);
    }
}
