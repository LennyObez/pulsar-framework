<?php

declare(strict_types=1);

namespace Pulsar\Queue;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Context\CausationId;
use Pulsar\Context\CorrelationId;
use Pulsar\Context\RequestContext;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Queue\Attribute\EffectClassification;
use Pulsar\Queue\Attribute\EffectClassifier;
use Pulsar\Queue\Envelope\EnvelopeSerializer;
use Pulsar\Queue\Envelope\JobEnvelope;
use Pulsar\Queue\Event\JobCompleted;
use Pulsar\Queue\Event\JobFailed;
use Pulsar\Queue\Event\JobRetried;
use Pulsar\Queue\Exception\QueueException;
use Pulsar\Queue\Middleware\MiddlewarePipeline;
use Pulsar\Queue\Monitor\MetricsCollector;
use Pulsar\Queue\Retry\QueueRetryPolicy;
use Pulsar\Queue\Retry\RetryDecision;
use Pulsar\Queue\Serialization\TypeRegistry;
use Throwable;

use function class_exists;
use function function_exists;
use function hrtime;
use function memory_get_usage;
use function sprintf;
use function time;
use function usleep;

use const PHP_OS_FAMILY;
use const SIGINT;
use const SIGTERM;

/**
 * Long-running worker that polls a queue and processes jobs through
 * the envelope middleware pipeline.
 *
 * Pops job records from the driver, deserializes them into envelopes,
 * runs the execution middleware pipeline (decryption, dedup, rate limiting),
 * and executes the job. Handles retries with effect-aware classification:
 * non-idempotent jobs are never auto-retried.
 *
 * Emits lifecycle events (JobCompleted, JobFailed, JobRetried) and records
 * processing metrics. Supports graceful shutdown via POSIX signals on Unix
 * and polling-based status checks on Windows.
 * @api
 */
#[Api(since: '1.0.0')]
final class Worker
{
    public protected(set) WorkerStatus $status = WorkerStatus::Stopped;

    private readonly EnvelopeSerializer $envelopeSerializer;

    public function __construct(
        private readonly QueueDriverInterface $driver,
        private readonly WorkerOptions $options,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?RequestContextHolder $contextHolder = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ?MetricsCollector $metrics = null,
        private readonly ?EffectClassifier $classifier = null,
        private readonly ?DeadLetterQueue $deadLetterQueue = null,
        private readonly ?TypeRegistry $typeRegistry = null,
        private readonly ?QueueRetryPolicy $retryPolicy = null,
        private readonly MiddlewarePipeline $executionPipeline = new MiddlewarePipeline(),
    ) {
        $this->envelopeSerializer = new EnvelopeSerializer();
    }

    /**
     * Start the blocking poll loop on the given queue.
     *
     * The worker will continue processing jobs until one of the following:
     * - The maximum job count is reached.
     * - The memory limit is exceeded.
     * - The time limit expires.
     * - A shutdown signal is received (SIGINT/SIGTERM on Unix).
     */
    public function run(string $queue): void
    {
        $this->status = WorkerStatus::Running;
        $startedAt = time();
        $processedCount = 0;

        $this->registerSignalHandlers();

        $this->logger?->info(sprintf('Queue worker started on "%s"', $queue));

        while ($this->status === WorkerStatus::Running) {
            if ($this->shouldRecycle($processedCount, $startedAt)) {
                $this->logger?->info('Worker recycling due to resource limits');
                break;
            }

            $processed = $this->processNextJob($queue);

            if ($processed) {
                $processedCount++;
            } else {
                usleep($this->options->sleepMs * 1000);
            }

            $this->checkSignals();
        }

        $this->status = WorkerStatus::Stopped;

        $this->logger?->info(sprintf(
            'Queue worker stopped after processing %d job(s)',
            $processedCount,
        ));
    }

    /**
     * Attempt to pop and process the next available job from the queue.
     *
     * Deserializes the job record payload into a {@see JobEnvelope}, runs
     * the execution middleware pipeline, and executes the job. On failure,
     * determines retry eligibility based on effect classification.
     *
     * @return bool True if a job was processed, false if the queue was empty.
     */
    public function processNextJob(string $queue): bool
    {
        $record = $this->driver->pop($queue);

        if ($record === null) {
            return false;
        }

        $this->logger?->debug(sprintf('Processing job "%s" [%s]', $record->id, $record->jobClass));

        $startTime = hrtime(true);

        try {
            $envelope = $this->envelopeSerializer->deserialize($record->payload);
        } catch (Throwable $e) {
            $this->driver->reject($record->id, 'Envelope deserialization failed: ' . $e->getMessage());
            $this->logger?->error(sprintf(
                'Job "%s" envelope deserialization failed: %s',
                $record->id,
                $e->getMessage(),
            ));
            $this->metrics?->recordFailed($queue);

            return true;
        }

        try {
            $this->executionPipeline->process(
                $envelope,
                function (JobEnvelope $e): null {
                    $this->executeJob($e);

                    return null;
                },
            );

            $durationMs = (float) (hrtime(true) - $startTime) / 1_000_000.0;

            $this->driver->acknowledge($record->id);
            $this->metrics?->recordProcessed($queue, $durationMs);

            $this->eventDispatcher?->dispatch(new JobCompleted(
                jobId: $envelope->id,
                queue: $queue,
                jobClass: $envelope->jobClass,
                occurredAt: time(),
                attempt: $envelope->attempt,
                durationMs: $durationMs,
            ));

            $this->logger?->debug(sprintf('Job "%s" completed successfully', $record->id));
        } catch (Throwable $e) {
            $this->metrics?->recordFailed($queue);
            $this->handleFailure($record, $envelope, $e, $queue);
        }

        return true;
    }

    /**
     * Request a graceful shutdown of the worker.
     */
    public function stop(): void
    {
        $this->status = WorkerStatus::Stopping;
    }

    /**
     * Instantiate and execute the job from a decrypted, validated envelope.
     *
     * Validates the job class against the type registry, restores request
     * context from envelope fields, and delegates to the job's handle method.
     */
    private function executeJob(JobEnvelope $envelope): void
    {
        $jobClass = $envelope->jobClass;

        if ($this->typeRegistry === null) {
            throw QueueException::typeNotAllowed(
                $jobClass . ' (TypeRegistry is required: no job class can be instantiated without a type allowlist)',
            );
        }

        $this->typeRegistry->assertAllowed($jobClass);

        if (!class_exists($jobClass)) {
            throw QueueException::serializationFailed($jobClass);
        }

        /** @var object $job */
        $job = new $jobClass();

        if (!$job instanceof QueueableInterface) {
            throw QueueException::serializationFailed($jobClass);
        }

        $requestContext = $this->buildRequestContext($envelope);

        if ($requestContext !== null) {
            $this->contextHolder?->set($requestContext);
        }

        $context = new JobContext(
            jobId: $envelope->id,
            queue: $envelope->queue,
            attempt: $envelope->attempt,
            maxAttempts: $envelope->retryMaxAttempts,
            requestContext: $requestContext,
        );

        try {
            $job->handle($context);
        } finally {
            $this->contextHolder?->clear();
            unset($job, $context);
        }
    }

    /**
     * Handle a failed job: determine retry eligibility, re-queue or dead-letter.
     *
     * Non-idempotent jobs (#[NonIdempotent]) are never auto-retried; they go
     * directly to the dead-letter queue. Idempotent and side-effect-free jobs
     * are retried per the retry policy until max attempts are exhausted.
     */
    private function handleFailure(
        JobRecord $record,
        JobEnvelope $envelope,
        Throwable $exception,
        string $queue,
    ): void {
        $this->logger?->error(sprintf(
            'Job "%s" failed on attempt %d: %s',
            $record->id,
            $envelope->attempt,
            $exception->getMessage(),
        ));

        $this->eventDispatcher?->dispatch(new JobFailed(
            jobId: $envelope->id,
            queue: $queue,
            jobClass: $envelope->jobClass,
            occurredAt: time(),
            attempt: $envelope->attempt,
            exceptionClass: $exception::class,
            exceptionMessage: $exception->getMessage(),
        ));

        if ($this->isNonIdempotent($envelope->jobClass)) {
            $this->sendToDeadLetter($record, $envelope, $exception);

            return;
        }

        $policy = $this->resolveRetryPolicy($envelope);
        $decision = $policy->shouldRetry($envelope->attempt, $exception);

        match ($decision) {
            RetryDecision::Retry => $this->retryJob($record, $envelope, $queue, $policy),
            RetryDecision::DeadLetter => $this->sendToDeadLetter($record, $envelope, $exception),
            RetryDecision::Discard => $this->discardJob($record, $exception),
        };
    }

    /**
     * Re-queue a failed job with exponential backoff delay.
     */
    private function retryJob(
        JobRecord $record,
        JobEnvelope $envelope,
        string $queue,
        QueueRetryPolicy $policy,
    ): void {
        $nextEnvelope = $envelope->withNextAttempt();
        $delayMs = $policy->getDelay($envelope->attempt);
        $delaySeconds = (int) ($delayMs / 1000);

        $serialized = $this->envelopeSerializer->serialize($nextEnvelope);
        $this->driver->push($queue, $nextEnvelope->jobClass, $serialized, $delaySeconds);
        $this->driver->acknowledge($record->id);

        $this->eventDispatcher?->dispatch(new JobRetried(
            jobId: $envelope->id,
            queue: $queue,
            jobClass: $envelope->jobClass,
            occurredAt: time(),
            attempt: $envelope->attempt,
            nextAttempt: $nextEnvelope->attempt,
            delayMs: $delayMs,
        ));

        $this->logger?->info(sprintf(
            'Job "%s" scheduled for retry (attempt %d -> %d, delay %dms)',
            $record->id,
            $envelope->attempt,
            $nextEnvelope->attempt,
            $delayMs,
        ));
    }

    /**
     * Send a failed job to the dead-letter queue.
     */
    private function sendToDeadLetter(
        JobRecord $record,
        JobEnvelope $envelope,
        Throwable $exception,
    ): void {
        $this->driver->reject($record->id, $exception->getMessage());

        $this->deadLetterQueue?->store(
            $record,
            $exception->getMessage(),
            $envelope->correlationId,
        );

        $this->logger?->warning(sprintf(
            'Job "%s" sent to dead-letter queue: %s',
            $record->id,
            $exception->getMessage(),
        ));
    }

    /**
     * Discard a failed job without dead-lettering.
     */
    private function discardJob(JobRecord $record, Throwable $exception): void
    {
        $this->driver->reject($record->id, 'Discarded: ' . $exception->getMessage());

        $this->logger?->info(sprintf(
            'Job "%s" discarded: %s',
            $record->id,
            $exception->getMessage(),
        ));
    }

    /**
     * Check whether a job class is classified as non-idempotent.
     *
     * Returns false if no classifier is configured (permissive default).
     */
    private function isNonIdempotent(string $jobClass): bool
    {
        if ($this->classifier === null) {
            return false;
        }

        try {
            /** @var class-string $jobClass */
            $classification = $this->classifier->classify($jobClass);

            return $classification === EffectClassification::NonIdempotent;
        } catch (QueueException) {
            return false;
        }
    }

    /**
     * Resolve the retry policy: use injected policy or build from envelope params.
     */
    private function resolveRetryPolicy(JobEnvelope $envelope): QueueRetryPolicy
    {
        if ($this->retryPolicy !== null) {
            return $this->retryPolicy;
        }

        return new QueueRetryPolicy(
            maxAttempts: $envelope->retryMaxAttempts,
            baseDelayMs: $envelope->retryDelayMs,
            maxDelayMs: $envelope->retryDelayMs * 32,
            multiplier: 2.0,
        );
    }

    /**
     * Build request context from envelope fields for downstream propagation.
     *
     * Creates a {@see RequestContext} from the envelope's correlation ID,
     * tenant ID, and subject ID. Returns null if no context data is available.
     */
    private function buildRequestContext(JobEnvelope $envelope): ?RequestContext
    {
        if ($envelope->correlationId === '') {
            return null;
        }

        return new RequestContext(
            correlationId: CorrelationId::fromString($envelope->correlationId),
            causationId: CausationId::fromString($envelope->id),
            actor: $envelope->subjectId,
            tenantId: $envelope->tenantId,
        );
    }

    /**
     * Register POSIX signal handlers for graceful shutdown on Unix systems.
     */
    private function registerSignalHandlers(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        if (!function_exists('pcntl_signal')) {
            return;
        }

        /** @psalm-suppress UndefinedConstant SIGINT/SIGTERM are POSIX-only, guarded by OS check above */
        $sigint = SIGINT;
        /** @psalm-suppress UndefinedConstant */
        $sigterm = SIGTERM;

        pcntl_signal($sigint, function (): void {
            $this->stop();
        });

        pcntl_signal($sigterm, function (): void {
            $this->stop();
        });
    }

    /**
     * Dispatch pending signals on Unix, or check status on Windows.
     */
    private function checkSignals(): void
    {
        if ($this->status === WorkerStatus::Stopping) {
            $this->status = WorkerStatus::Stopped;
            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }

    /**
     * Determine whether the worker should recycle based on resource limits.
     */
    private function shouldRecycle(int $processedCount, int $startedAt): bool
    {
        if ($this->options->maxJobs > 0 && $processedCount >= $this->options->maxJobs) {
            return true;
        }

        $memoryUsageMb = (int) ((float) memory_get_usage(true) / 1024.0 / 1024.0);
        if ($this->options->maxMemoryMb > 0 && $memoryUsageMb >= $this->options->maxMemoryMb) {
            return true;
        }

        $elapsed = time() - $startedAt;

        return $this->options->timeLimitSeconds > 0 && $elapsed >= $this->options->timeLimitSeconds;
    }
}
