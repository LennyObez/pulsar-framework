<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\EInvoicing;

use Pulsar\Api\Api;

/**
 * Result of EN 16931 invoice validation.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ValidationResult
{
    /**
     * @param list<ValidationViolation> $violations
     */
    public function __construct(
        public bool $valid,
        public array $violations,
    ) {}

    /**
     * Create a passing validation result.
     */
    public static function pass(): self
    {
        return new self(valid: true, violations: []);
    }

    /**
     * Create a failing validation result.
     *
     * @param list<ValidationViolation> $violations
     */
    public static function fail(array $violations): self
    {
        return new self(valid: false, violations: $violations);
    }
}
