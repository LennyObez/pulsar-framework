<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation;

use InvalidArgumentException;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\Rule\Required;

use function array_is_list;
use function sprintf;

/**
 * Stateless request data validator.
 *
 * Accepts an associative array of field names mapped to lists of rules,
 * then runs each rule against the corresponding input value.
 *
 * Short-circuit policy:
 *   - `Required` failure → remaining rules for that field are skipped.
 *   - `TypeRuleInterface` failure (`IntegerType`, `StringType`,
 *     `BooleanType`, `ArrayType`) → remaining rules skipped, since
 *     downstream rules (`Min`, `Between`, `MinLength`, …) would either
 *     misbehave or pile cascading violations on top of a single
 *     type-mismatch root cause.
 * @api
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

            /** @var mixed $value */
            $value = $data[$field] ?? null;

            foreach ($fieldRules as $rule) {
                $violation = $rule->validate($field, $value, $data);

                if ($violation !== null) {
                    $violations[] = $violation;

                    // F7.7: short-circuit on Required OR any type-rule
                    // failure. Running e.g. `Min` against a value that
                    // is not an integer in the first place stacks
                    // confusing cascade violations on top of the real
                    // root cause.
                    if ($rule instanceof Required || $rule instanceof TypeRuleInterface) {
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
