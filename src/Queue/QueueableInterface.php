<?php

declare(strict_types=1);

namespace Pulsar\Queue;

use Pulsar\Api\Api;

/**
 * Contract for classes that can be dispatched as queued jobs.
 */
#[Api(since: '1.0.0')]
interface QueueableInterface
{
    /**
     * Execute the job logic.
     */
    public function handle(JobContext $context): void;

    /**
     * The default queue name for this job.
     */
    public function queue(): string;

    /**
     * Maximum number of attempts before the job is considered failed.
     */
    public function maxAttempts(): int;

    /**
     * Maximum execution time in seconds before the job is terminated.
     */
    public function timeout(): int;
}
