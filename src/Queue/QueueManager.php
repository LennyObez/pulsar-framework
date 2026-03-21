<?php

declare(strict_types=1);

namespace Pulsar\Queue;

use Pulsar\Api\Api;
use Pulsar\Config\QueueConfig;
use Pulsar\Config\QueueDriverType;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Queue\Driver\AmqpDriver;
use Pulsar\Queue\Driver\Config\AmqpDriverConfig;
use Pulsar\Queue\Driver\Config\PubSubDriverConfig;
use Pulsar\Queue\Driver\Config\RedisDriverConfig;
use Pulsar\Queue\Driver\Config\SqsDriverConfig;
use Pulsar\Queue\Driver\InMemoryDriver;
use Pulsar\Queue\Driver\PubSubDriver;
use Pulsar\Queue\Driver\RedisDriver;
use Pulsar\Queue\Driver\SqsDriver;
use Pulsar\Queue\Driver\SyncDriver;
use Pulsar\Queue\Envelope\BackoffStrategy;
use Pulsar\Queue\Envelope\EnvelopeSerializer;
use Pulsar\Queue\Envelope\JobEnvelope;
use Pulsar\Queue\Event\JobDispatched;
use Pulsar\Queue\Exception\QueueException;
use Pulsar\Queue\Middleware\MiddlewarePipeline;
use Pulsar\Queue\Monitor\MetricsCollector;
use Pulsar\Queue\Serialization\SchemaVersionRegistry;
use Pulsar\Queue\Serialization\TypeRegistry;
use Random\Engine\Secure;
use Random\Randomizer;

use function bin2hex;
use function time;

/**
 * Central orchestrator for dispatching jobs and querying queue state.
 *
 * Builds job envelopes, runs the dispatch middleware pipeline (context
 * propagation, effect enforcement, encryption), serializes envelopes,
 * and pushes them onto the resolved queue driver. Emits lifecycle events
 * and records dispatch metrics when the respective services are available.
 * @api
 */
#[Api(since: '1.0.0')]
final class QueueManager
{
    private ?QueueDriverInterface $resolvedDriver;

    private readonly Randomizer $randomizer;

    private readonly EnvelopeSerializer $envelopeSerializer;

    public function __construct(
        private readonly QueueConfig $config,
        ?QueueDriverInterface $driver = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ?MetricsCollector $metrics = null,
        private readonly ?TypeRegistry $typeRegistry = null,
        private readonly ?SchemaVersionRegistry $schemaVersionRegistry = null,
        private readonly MiddlewarePipeline $dispatchPipeline = new MiddlewarePipeline(),
    ) {
        $this->resolvedDriver = $driver;
        $this->randomizer = new Randomizer(new Secure());
        $this->envelopeSerializer = new EnvelopeSerializer();
    }

    /**
     * Dispatch a job onto the queue via the envelope pipeline.
     *
     * Builds a {@see JobEnvelope}, runs it through the dispatch middleware
     * pipeline (context propagation, effect enforcement, encryption),
     * serializes the result, and pushes it to the driver.
     *
     * @param string               $jobClass       Fully-qualified class name of the job.
     * @param string               $payload        Serialized job payload.
     * @param string|null          $queue          Target queue name (defaults to config default).
     * @param int                  $delay          Delay in seconds before the job becomes available.
     * @param string|null          $idempotencyKey Deduplication key (empty string disables dedup).
     * @param string|null          $subjectId      Identity of the actor dispatching the job.
     * @param string|null          $batchId        Batch identifier for grouped jobs.
     * @param int|null             $chainIndex     Position in a job chain (null if not chained).
     * @param array<string, mixed> $metadata       Extensible metadata bag.
     *
     * @return string The unique envelope ID assigned to the dispatched job.
     */
    public function dispatch(
        string $jobClass,
        string $payload,
        ?string $queue = null,
        int $delay = 0,
        ?string $idempotencyKey = null,
        ?string $subjectId = null,
        ?string $batchId = null,
        ?int $chainIndex = null,
        array $metadata = [],
    ): string {
        $this->typeRegistry?->assertAllowed($jobClass);

        $targetQueue = $queue ?? $this->config->defaultQueue;
        $schemaVersion = $this->schemaVersionRegistry?->currentVersion($jobClass) ?? 1;

        $envelope = new JobEnvelope(
            id: $this->generateId(),
            jobClass: $jobClass,
            payload: $payload,
            queue: $targetQueue,
            idempotencyKey: $idempotencyKey ?? '',
            correlationId: '',
            traceId: null,
            spanId: null,
            schemaVersion: $schemaVersion,
            keyId: null,
            retryMaxAttempts: $this->config->retryMaxAttempts,
            retryBackoffStrategy: BackoffStrategy::Exponential,
            retryDelayMs: $this->config->retryBaseDelayMs,
            tenantId: null,
            subjectId: $subjectId,
            batchId: $batchId,
            chainIndex: $chainIndex,
            attempt: 1,
            dispatchedAt: time(),
            encrypted: false,
            metadata: $metadata,
        );

        return $this->pushEnvelope($envelope, $targetQueue, $delay);
    }

    /**
     * Dispatch a pre-built job envelope onto the queue.
     *
     * Runs the envelope through the dispatch middleware pipeline, serializes,
     * and pushes it to the driver. Use this for advanced dispatch scenarios
     * (batching, chaining) where the envelope is pre-configured.
     *
     * @param int $delay Delay in seconds before the job becomes available.
     *
     * @return string The envelope ID.
     */
    public function dispatchEnvelope(JobEnvelope $envelope, int $delay = 0): string
    {
        $this->typeRegistry?->assertAllowed($envelope->jobClass);

        return $this->pushEnvelope($envelope, $envelope->queue, $delay);
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
            QueueDriverType::Redis => new RedisDriver(
                RedisDriverConfig::fromArray($this->config->driverOptions),
            ),
            QueueDriverType::Amqp => new AmqpDriver(
                AmqpDriverConfig::fromArray($this->config->driverOptions),
            ),
            QueueDriverType::Sqs => new SqsDriver(
                SqsDriverConfig::fromArray($this->config->driverOptions),
            ),
            QueueDriverType::PubSub => new PubSubDriver(
                PubSubDriverConfig::fromArray($this->config->driverOptions),
            ),
        };

        return $this->resolvedDriver;
    }

    /**
     * Run the envelope through the dispatch pipeline, serialize, and push to the driver.
     */
    private function pushEnvelope(JobEnvelope $envelope, string $queue, int $delay): string
    {
        /** @var string $jobId */
        $jobId = $this->dispatchPipeline->process(
            $envelope,
            function (JobEnvelope $e) use ($delay): string {
                $serialized = $this->envelopeSerializer->serialize($e);
                $this->driver()->push($e->queue, $e->jobClass, $serialized, $delay);

                return $e->id;
            },
        );

        $this->metrics?->recordDispatched($queue);

        $this->eventDispatcher?->dispatch(new JobDispatched(
            jobId: $jobId,
            queue: $queue,
            jobClass: $envelope->jobClass,
            occurredAt: time(),
            delaySeconds: $delay,
        ));

        return $jobId;
    }

    /**
     * Generate a unique job identifier (32 hex characters).
     */
    private function generateId(): string
    {
        return bin2hex($this->randomizer->getBytes(16));
    }
}
