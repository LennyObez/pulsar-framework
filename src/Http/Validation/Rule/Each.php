<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Rule;

use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;
use Pulsar\Http\Validation\Violation;

use function array_values;
use function is_array;
use function sprintf;

/**
 * Apply rules to each element of an array. Returns the first violation found.
 * Skips null values. Fails if value is not an array.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Each implements RuleInterface
{
    /** @var list<RuleInterface> */
    private array $rules;

    public function __construct(
        RuleInterface ...$rules,
    ) {
        $this->rules = array_values($rules);
    }

    #[Override]
    public function validate(string $field, mixed $value, array $data): ?Violation
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            return new Violation(
                field: $field,
                message: sprintf('The %s field must be an array.', $field),
                rule: $this->name(),
            );
        }

        /** @var mixed $element */
        foreach ($value as $index => $element) {
            $elementField = sprintf('%s.%s', $field, $index);

            foreach ($this->rules as $rule) {
                $violation = $rule->validate($elementField, $element, $data);

                if ($violation !== null) {
                    return $violation;
                }
            }
        }

        return null;
    }

    #[Override]
    public function name(): string
    {
        return 'each';
    }
}
