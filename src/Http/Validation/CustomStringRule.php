<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation;

use Override;
use Pulsar\Api\Internal;

use function sprintf;

/**
 * Fallback rule for unrecognized string rule names.
 *
 * Allows extension of the string-based rule syntax without
 * modifying the core parser. Always passes validation: use
 * concrete RuleInterface implementations for actual validation.
 */
#[Internal(reason: 'Fallback for unrecognized string rules')]
final readonly class CustomStringRule implements RuleInterface
{
    public function __construct(
        private string $ruleName,
        private string $parameter = '',
    ) {}

    #[Override]
    public function validate(string $field, mixed $value, array $data): Violation
    {
        // Unrecognized rules fail with an informative message
        return new Violation(
            field: $field,
            message: sprintf('Unknown validation rule "%s" for field "%s".', $this->ruleName, $field),
            rule: $this->ruleName,
            code: 'VALIDATION_UNKNOWN_RULE',
        );
    }

    #[Override]
    public function name(): string
    {
        return $this->ruleName;
    }

    public function parameter(): string
    {
        return $this->parameter;
    }
}
