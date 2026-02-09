<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Repair;

use Pulsar\Api\Api;

/**
 * Diagnosis result from a repair job.
 */
#[Api(since: '1.0.0')]
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
