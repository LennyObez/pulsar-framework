<?php

declare(strict_types=1);

namespace Pulsar\Supervisor\InvariantCheck;

use Pulsar\Api\Api;

/**
 * Immutable result of a single invariant check.
 */
#[Api]
final readonly class InvariantCheckResult
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
