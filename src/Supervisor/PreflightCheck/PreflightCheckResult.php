<?php

declare(strict_types=1);

namespace Pulsar\Supervisor\PreflightCheck;

use Pulsar\Api\Api;

/**
 * Immutable result of a single preflight check.
 */
#[Api(since: '1.0.0')]
final readonly class PreflightCheckResult
{
    /**
     * @param list<string> $findings
     */
    public function __construct(
        public bool $passed,
        public string $message,
        public array $findings = [],
    ) {}
}
