<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Contracts;

use Pulsar\Api\Api;

/**
 * Result of a monitoring hook check.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MonitoringResult
{
    /**
     * @param bool $healthy Whether the check passed
     * @param non-empty-string $hookName Name of the hook that produced this result
     * @param non-empty-string $message Human-readable summary
     * @param array<string, mixed> $metrics Collected metrics from the check
     */
    public function __construct(
        public bool $healthy,
        public string $hookName,
        public string $message,
        public array $metrics = [],
    ) {}
}
