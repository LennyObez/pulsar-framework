<?php

declare(strict_types=1);

namespace Pulsar\Security\Assertion;

use Pulsar\Api\Api;

/**
 * Represents a single security assertion violation.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SecurityViolation
{
    public function __construct(
        public string $assertion,
        public string $message,
        public SecurityViolationSeverity $severity,
    ) {}
}
