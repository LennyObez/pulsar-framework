<?php

declare(strict_types=1);

namespace Pulsar\Resilience\Repair;

use Pulsar\Api\Api;
use Throwable;

/**
 * Result of a repair action.
 */
#[Api(since: '1.0.0')]
final readonly class RepairResult
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
