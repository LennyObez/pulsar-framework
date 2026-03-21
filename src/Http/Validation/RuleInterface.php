<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation;

use Pulsar\Api\Api;

/**
 * Contract for a single validation rule.
 *
 * Rules inspect a field value and return a Violation on failure,
 * or null when the value passes validation.
 * @api
 */
#[Api(since: '1.0.0')]
interface RuleInterface
{
    /**
     * Validate the given value for the named field.
     *
     * @param array<string, mixed> $data Full input data (for cross-field rules)
     */
    public function validate(string $field, mixed $value, array $data): ?Violation;

    /**
     * Short machine-readable name of this rule (e.g. "required", "min_length").
     */
    public function name(): string;
}
