<?php

declare(strict_types=1);

namespace Pulsar\Scheduler;

use Pulsar\Api\Api;

/**
 * Contract for registering scheduled jobs by class name and schedule.
 */
#[Api(since: '1.0.0')]
interface JobRegistryInterface
{
    /**
     * Register a job class to run on a given schedule.
     *
     * @param class-string $jobClass
     */
    public function register(string $jobClass, Schedule $schedule): void;
}
