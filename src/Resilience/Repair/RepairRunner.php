<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Repair;

use Pulsar\Resilience\Exception\ResilienceException;
use Throwable;

use function sprintf;

/**
 * Orchestrates repair job diagnosis and execution.
 */
final class RepairRunner implements RepairRunnerInterface
{
    /** @var array<string, RepairJobInterface> */
    private array $jobs = [];

    /**
     * Register a repair job.
     */
    public function register(RepairJobInterface $job): void
    {
        $this->jobs[$job->getName()] = $job;
    }

    /**
     * Run diagnosis on all registered repair jobs.
     *
     * @return list<RepairDiagnosis>
     */
    public function diagnoseAll(): array
    {
        $results = [];

        foreach ($this->jobs as $job) {
            $results[] = $job->diagnose();
        }

        return $results;
    }

    /**
     * Run all repair jobs that need repair.
     *
     * @return list<RepairResult>
     */
    public function repairAll(): array
    {
        $results = [];

        foreach ($this->jobs as $job) {
            $diagnosis = $job->diagnose();

            if (!$diagnosis->needsRepair) {
                continue;
            }

            try {
                $results[] = $job->repair();
            } catch (Throwable $e) {
                $results[] = new RepairResult(
                    repairJobName: $job->getName(),
                    success: false,
                    description: sprintf('Repair failed with exception: %s', $e->getMessage()),
                    exception: $e,
                );
            }
        }

        return $results;
    }

    /**
     * Run a specific repair job by name.
     *
     * @throws ResilienceException If the repair job is not registered.
     */
    public function repair(string $name): RepairResult
    {
        if (!isset($this->jobs[$name])) {
            throw ResilienceException::repairFailed($name, 'repair job not registered');
        }

        return $this->jobs[$name]->repair();
    }

    /**
     * Get all registered repair job names.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->jobs);
    }
}
