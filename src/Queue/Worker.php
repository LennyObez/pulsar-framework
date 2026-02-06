<?php

declare(strict_types=1);

namespace Pulsar\Queue;

use function class_exists;
use function function_exists;
use function memory_get_usage;

use const PHP_OS_FAMILY;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Queue\Exception\QueueException;

use const SIGINT;
use const SIGTERM;

use function sprintf;

use Throwable;

use function time;
use function usleep;

/**
 * Long-running worker that polls a queue and processes jobs.
 *
 * Handles graceful shutdown via POSIX signals on Unix systems and
 * polling-based status checks on Windows. Automatically recycles
 * when memory, job count, or time limits are exceeded.
 */
#[Api]
final class Worker
{
    public protected(set) WorkerStatus $status = WorkerStatus::Stopped;

    public function __construct(
        private readonly QueueDriverInterface $driver,
        private readonly WorkerOptions $options,
        private readonly ?LoggerInterface $logger = null,
    ) {}

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
     * @return bool True if a job was processed, false if the queue was empty.
     */
    public function processNextJob(string $queue): bool
    {
        $record = $this->driver->pop($queue);

        if ($record === null) {
            return false;
        }

        $this->logger?->debug(sprintf('Processing job "%s" [%s]', $record->id, $record->jobClass));

        try {
            $this->executeJob($record);
            $this->driver->acknowledge($record->id);

            $this->logger?->debug(sprintf('Job "%s" completed successfully', $record->id));
        } catch (Throwable $e) {
            $this->driver->reject($record->id, $e->getMessage());

            $this->logger?->error(sprintf(
                'Job "%s" failed: %s',
                $record->id,
                $e->getMessage(),
            ));
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
     * Instantiate and execute the job class.
     */
    private function executeJob(JobRecord $record): void
    {
        $jobClass = $record->jobClass;

        if (!class_exists($jobClass)) {
            throw QueueException::serializationFailed($jobClass);
        }

        /** @var object $job */
        $job = new $jobClass();

        if (!$job instanceof QueueableInterface) {
            throw QueueException::serializationFailed($jobClass);
        }

        $context = new JobContext(
            jobId: $record->id,
            queue: $record->queue,
            attempt: $record->attempts,
            maxAttempts: $job->maxAttempts(),
        );

        try {
            $job->handle($context);
        } finally {
            unset($job, $context);
        }
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

        /** @psalm-suppress UndefinedConstant — SIGINT/SIGTERM are POSIX-only, guarded by OS check above */
        pcntl_signal(SIGINT, function (): void {
            $this->stop();
        });

        /** @psalm-suppress UndefinedConstant */
        pcntl_signal(SIGTERM, function (): void {
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
        if ($this->options->timeLimitSeconds > 0 && $elapsed >= $this->options->timeLimitSeconds) {
            return true;
        }

        return false;
    }
}
