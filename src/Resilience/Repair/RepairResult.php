<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Repair;

use Throwable;

/**
 * Result of a repair action.
 */
readonly class RepairResult
{
    /**
     * @param list<string> $actionsPerformed
     */
    public function __construct(
        public string $repairJobName,
        public bool $success,
        public string $description,
        public array $actionsPerformed = [],
        public ?Throwable $exception = null,
    ) {}
}
