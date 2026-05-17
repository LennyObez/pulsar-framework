<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation;

use Pulsar\Api\Api;

use function array_filter;
use function array_map;
use function array_values;

/**
 * Immutable collection of validation violations.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ValidationResult
{
    /** @var list<Violation> */
    public array $violations;

    /**
     * @param list<Violation> $violations
     */
    public function __construct(array $violations = [])
    {
        $this->violations = $violations;
    }

    /**
     * Whether validation passed (no violations).
     */
    public function passed(): bool
    {
        return $this->violations === [];
    }

    /**
     * Whether validation failed (has violations).
     */
    public function failed(): bool
    {
        return $this->violations !== [];
    }

    /**
     * Get all violations for a specific field.
     *
     * @return list<Violation>
     */
    public function forField(string $field): array
    {
        return array_values(array_filter(
            $this->violations,
            static fn(Violation $v): bool => $v->field === $field,
        ));
    }

    /**
     * Get the first violation for a specific field, or null.
     */
    public function firstForField(string $field): ?Violation
    {
        /** @var Violation|null */
        return array_find(
            $this->violations,
            static fn(Violation $v): bool => $v->field === $field,
        );
    }

    /**
     * @return list<array{field: string, message: string, rule: string, code: string}>
     */
    public function toArray(): array
    {
        return array_map(
            static fn(Violation $v): array => $v->toArray(),
            $this->violations,
        );
    }
}
