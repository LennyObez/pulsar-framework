<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function array_key_exists;
use function gettype;
use function is_scalar;
use function sprintf;

/**
 * Field is required when another field equals an expected value.
 * Does NOT skip null; it checks the condition and fails if required and missing.
 */
#[Api(since: '1.0.0')]
final readonly class RequiredIf implements RuleInterface
{
    public function __construct(
        private string $otherField,
        private mixed $expectedValue,
        private string $message = '',
    ) {}

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        $conditionMet = array_key_exists($this->otherField, $data)
            && $data[$this->otherField] === $this->expectedValue;

        if (!$conditionMet) {
            return null;
        }

        $missing = $value === null || $value === '' || $value === [];

        if ($missing) {
            return new Violation(
                field: $field,
                message: $this->message !== '' ? $this->message : sprintf(
                    'The %s field is required when %s is %s.',
                    $field,
                    $this->otherField,
                    is_scalar($this->expectedValue) ? (string) $this->expectedValue : gettype($this->expectedValue),
                ),
                rule: $this->name(),
            );
        }

        return null;
    }

    #[Override]
    public function name(): string
    {
        return 'required_if';
    }
}
