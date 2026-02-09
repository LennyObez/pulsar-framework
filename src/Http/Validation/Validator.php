<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation;

use function array_is_list;

use InvalidArgumentException;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\Rule\Required;

use function sprintf;

/**
 * Stateless request data validator.
 *
 * Accepts an associative array of field names mapped to lists of rules,
 * then runs each rule against the corresponding input value.
 *
 * When a Required rule fails for a field, remaining rules for that field
 * are skipped (short-circuit).
 */
#[Api(since: '1.0.0')]
final class Validator
{
    /**
     * Validate data against a set of rules.
     *
     * @param array<string, mixed> $data Input data to validate
     * @param array<string, list<RuleInterface>> $rules Field → rule-list map
     */
    #[NoDiscard]
    public function validate(array $data, array $rules): ValidationResult
    {
        /** @var list<Violation> $violations */
        $violations = [];

        foreach ($rules as $field => $fieldRules) {
            if (!array_is_list($fieldRules)) {
                throw new InvalidArgumentException(sprintf('Rules for field "%s" must be a list', $field));
            }

            $value = $data[$field] ?? null;

            foreach ($fieldRules as $rule) {
                $violation = $rule->validate($field, $value, $data);

                if ($violation !== null) {
                    $violations[] = $violation;

                    // Short-circuit: if Required fails, skip remaining rules for this field
                    if ($rule instanceof Required) {
                        break;
                    }
                }
            }
        }

        return new ValidationResult($violations);
    }

    /**
     * Validate data and throw on failure.
     *
     * @param array<string, mixed> $data Input data to validate
     * @param array<string, list<RuleInterface>> $rules Field → rule-list map
     *
     * @throws ValidationException When validation fails
     */
    public function validateOrFail(array $data, array $rules): ValidationResult
    {
        $result = $this->validate($data, $rules);

        if ($result->failed()) {
            throw new ValidationException($result);
        }

        return $result;
    }
}
