<?php

declare(strict_types=1);

namespace Pulsar\Testing\Fake;

use Closure;
use PHPUnit\Framework\Assert;
use Pulsar\Api\Api;
use Pulsar\Queue\JobRecord;
use Pulsar\Queue\JobRecordStatus;
use Pulsar\Queue\QueueDriverInterface;

use function array_filter;
use function array_values;
use function bin2hex;
use function count;
use function implode;
use function random_bytes;
use function sprintf;
use function time;

/**
 * Fake queue driver that records all pushed jobs for assertion.
 *
 * Jobs are stored in memory without actual processing, enabling tests
 * to verify which jobs were queued, on which queues, and with what payloads.
 * @api
 */
#[Api(since: '1.0.0')]
final class QueueFake implements QueueDriverInterface
{
    /** @var list<JobRecord> */
    private array $pushedJobs = [];

    public function push(string $queue, string $jobClass, string $payload, int $delay = 0): string
    {
        $id = bin2hex(random_bytes(16));

        $this->pushedJobs[] = new JobRecord(
            id: $id,
            queue: $queue,
            jobClass: $jobClass,
            payload: $payload,
            attempts: 0,
            status: JobRecordStatus::Pending,
            createdAt: time(),
            availableAt: time() + $delay,
        );

        return $id;
    }

    public function pop(string $queue): ?JobRecord
    {
        foreach ($this->pushedJobs as $index => $job) {
            if ($job->queue === $queue && $job->status === JobRecordStatus::Pending) {
                $this->pushedJobs[$index] = new JobRecord(
                    id: $job->id,
                    queue: $job->queue,
                    jobClass: $job->jobClass,
                    payload: $job->payload,
                    attempts: $job->attempts + 1,
                    status: JobRecordStatus::Processing,
                    createdAt: $job->createdAt,
                    availableAt: $job->availableAt,
                );

                return $this->pushedJobs[$index];
            }
        }

        return null;
    }

    public function acknowledge(string $jobId): void
    {
        $this->updateStatus($jobId, JobRecordStatus::Completed);
    }

    public function reject(string $jobId, string $reason): void
    {
        $this->updateStatus($jobId, JobRecordStatus::Failed);
    }

    public function size(string $queue): int
    {
        return count(array_filter(
            $this->pushedJobs,
            static fn(JobRecord $job): bool => $job->queue === $queue
                && $job->status === JobRecordStatus::Pending,
        ));
    }

    public function purge(string $queue): int
    {
        $before = count($this->pushedJobs);
        $this->pushedJobs = array_values(array_filter(
            $this->pushedJobs,
            static fn(JobRecord $job): bool => $job->queue !== $queue,
        ));

        return $before - count($this->pushedJobs);
    }

    /** @return list<JobRecord> */
    public function findByStatus(JobRecordStatus $status): array
    {
        return array_values(array_filter(
            $this->pushedJobs,
            static fn(JobRecord $job): bool => $job->status === $status,
        ));
    }

    /**
     * Assert that a job of the given class was pushed.
     *
     * @param int|null $count Exact number expected (null = at least one)
     */
    public function assertPushed(string $jobClass, ?int $count = null): void
    {
        $matching = $this->jobsOfType($jobClass);
        $matchCount = count($matching);

        if ($count !== null) {
            Assert::assertSame(
                $count,
                $matchCount,
                sprintf(
                    "Expected %d push(es) of [%s], but %d occurred.\nPushed jobs: %s",
                    $count,
                    $jobClass,
                    $matchCount,
                    $this->formatPushedList(),
                ),
            );
        } else {
            Assert::assertGreaterThan(
                0,
                $matchCount,
                sprintf(
                    "Expected job [%s] to be pushed, but it was not.\nPushed jobs: %s",
                    $jobClass,
                    $this->formatPushedList(),
                ),
            );
        }
    }

    /**
     * Assert that a job was pushed to a specific queue.
     */
    public function assertPushedOn(string $queue, string $jobClass): void
    {
        $matching = array_filter(
            $this->jobsOfType($jobClass),
            static fn(JobRecord $job): bool => $job->queue === $queue,
        );

        Assert::assertNotEmpty(
            $matching,
            sprintf(
                "Expected job [%s] to be pushed on queue [%s], but it was not.\nPushed jobs on [%s]: %s",
                $jobClass,
                $queue,
                $queue,
                $this->formatJobsOnQueue($queue),
            ),
        );
    }

    /**
     * Assert that a job was NOT pushed.
     */
    public function assertNotPushed(string $jobClass): void
    {
        $matching = $this->jobsOfType($jobClass);

        Assert::assertCount(
            0,
            $matching,
            sprintf(
                'Expected job [%s] NOT to be pushed, but it was pushed %d time(s).',
                $jobClass,
                count($matching),
            ),
        );
    }

    /**
     * Assert that no jobs were pushed.
     */
    public function assertNothingPushed(): void
    {
        Assert::assertCount(
            0,
            $this->pushedJobs,
            sprintf(
                "Expected no jobs to be pushed, but %d were.\nPushed jobs: %s",
                count($this->pushedJobs),
                $this->formatPushedList(),
            ),
        );
    }

    /**
     * Assert a job was pushed matching a callback.
     *
     * @param Closure(JobRecord): bool $callback
     */
    public function assertPushedWith(string $jobClass, Closure $callback): void
    {
        $matching = array_filter(
            $this->jobsOfType($jobClass),
            $callback,
        );

        Assert::assertNotEmpty(
            $matching,
            sprintf(
                "Expected job [%s] matching callback to be pushed, but none matched.\nTotal [%s] jobs pushed: %d",
                $jobClass,
                $jobClass,
                count($this->jobsOfType($jobClass)),
            ),
        );
    }

    /**
     * Get all pushed jobs.
     *
     * @return list<JobRecord>
     */
    public function pushed(): array
    {
        return $this->pushedJobs;
    }

    /**
     * Get all pushed jobs of a specific type.
     *
     * @return list<JobRecord>
     */
    public function jobsOfType(string $jobClass): array
    {
        return array_values(array_filter(
            $this->pushedJobs,
            static fn(JobRecord $job): bool => $job->jobClass === $jobClass,
        ));
    }

    /**
     * Reset all recorded state.
     */
    public function reset(): void
    {
        $this->pushedJobs = [];
    }

    private function updateStatus(string $jobId, JobRecordStatus $status): void
    {
        foreach ($this->pushedJobs as $index => $job) {
            if ($job->id === $jobId) {
                $this->pushedJobs[$index] = new JobRecord(
                    id: $job->id,
                    queue: $job->queue,
                    jobClass: $job->jobClass,
                    payload: $job->payload,
                    attempts: $job->attempts,
                    status: $status,
                    createdAt: $job->createdAt,
                    availableAt: $job->availableAt,
                );

                return;
            }
        }
    }

    private function formatPushedList(): string
    {
        if ($this->pushedJobs === []) {
            return '(none)';
        }

        $classes = [];

        foreach ($this->pushedJobs as $job) {
            if (!isset($classes[$job->jobClass])) {
                $classes[$job->jobClass] = 0;
            }

            ++$classes[$job->jobClass];
        }

        $parts = [];

        foreach ($classes as $class => $classCount) {
            $parts[] = sprintf('%s (%dx)', $class, $classCount);
        }

        return implode(', ', $parts);
    }

    private function formatJobsOnQueue(string $queue): string
    {
        $onQueue = array_filter(
            $this->pushedJobs,
            static fn(JobRecord $job): bool => $job->queue === $queue,
        );

        if ($onQueue === []) {
            return '(none)';
        }

        return implode(', ', array_map(
            static fn(JobRecord $job): string => $job->jobClass,
            $onQueue,
        ));
    }
}
