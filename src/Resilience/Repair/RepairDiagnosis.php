<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Repair;

/**
 * Diagnosis result from a repair job.
 */
readonly class RepairDiagnosis
{
    /**
     * @param list<string> $findings
     */
    public function __construct(
        public string $repairJobName,
        public bool $needsRepair,
        public string $description,
        public array $findings = [],
    ) {}
}
