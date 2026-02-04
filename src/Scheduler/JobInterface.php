<?php

declare(strict_types=1);

namespace Pulsar\Scheduler;

/**
 * Interface for scheduled jobs.
 */
interface JobInterface
{
    /**
     * Get the job name.
     */
    public function getName(): string;

    /**
     * Get the job schedule.
     */
    public function getSchedule(): Schedule;

    /**
     * Execute the job.
     */
    public function execute(JobContext $context): JobResult;

    /**
     * Get a description of the job.
     */
    public function getDescription(): string;
}
