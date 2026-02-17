<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\Rule\Email;
use Pulsar\Http\Validation\Rule\Max;
use Pulsar\Http\Validation\Rule\MaxLength;
use Pulsar\Http\Validation\Rule\Min;
use Pulsar\Http\Validation\Rule\MinLength;
use Pulsar\Http\Validation\Rule\Required;
use Pulsar\Http\Validation\Rule\StringType;
use Pulsar\Http\Validation\Rule\Url;

use function array_map;
use function explode;
use function is_string;

/**
 * Fluent validation builder for expressive inline validation.
 *
 * Usage:
 *   $result = ValidatorBuilder::make($data)
 *       ->rule('email', 'required|email')
 *       ->rule('name', 'required|string|max_length:255')
 *       ->validate();
 */
#[Api(since: '1.0.0')]
final class ValidatorBuilder
{
    /** @var array<string, list<RuleInterface>> */
    private array $rules = [];

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        private readonly array $data,
    ) {}

    /**
     * Create a builder for the given data.
     *
     * @param array<string, mixed> $data
     */
    public static function make(array $data): self
    {
        return new self($data);
    }

    /**
     * Add rules for a field.
     *
     * Rules can be a pipe-separated string (e.g. "required|email|max_length:255")
     * or a list of RuleInterface objects.
     *
     * @param string|list<RuleInterface> $rules
     *
     * @return $this
     */
    public function rule(string $field, string|array $rules): self
    {
        if (is_string($rules)) {
            $this->rules[$field] = [
                ...($this->rules[$field] ?? []),
                ...self::parseStringRules($rules),
            ];
        } else {
            $this->rules[$field] = [
                ...($this->rules[$field] ?? []),
                ...$rules,
            ];
        }

        return $this;
    }

    /**
     * Add rules for multiple fields at once.
     *
     * @param array<string, string|list<RuleInterface>> $rules
     *
     * @return $this
     */
    public function rules(array $rules): self
    {
        foreach ($rules as $field => $fieldRules) {
            $this->rule($field, $fieldRules);
        }

        return $this;
    }

    /**
     * Validate and return the result.
     */
    #[NoDiscard]
    public function validate(): ValidationResult
    {
        $validator = new Validator();

        return $validator->validate($this->data, $this->rules);
    }

    /**
     * Validate and throw on failure.
     *
     * @throws ValidationException
     */
    #[NoDiscard]
    public function validateOrFail(): ValidationResult
    {
        $validator = new Validator();

        return $validator->validateOrFail($this->data, $this->rules);
    }

    /**
     * Parse a pipe-separated rule string into RuleInterface objects.
     *
     * @return list<RuleInterface>
     */
    private static function parseStringRules(string $rules): array
    {
        $parts = explode('|', $rules);

        return array_values(array_map(self::parseRule(...), $parts));
    }

    /**
     * Parse a single rule string like "required", "max_length:255", "email".
     */
    private static function parseRule(string $rule): RuleInterface
    {
        $segments = explode(':', $rule, 2);
        $name = trim($segments[0]);
        $param = $segments[1] ?? '';

        return match ($name) {
            'required' => new Required(),
            'email' => new Email(),
            'string' => new StringType(),
            'url' => new Url(),
            'min' => new Min((int) $param),
            'max' => new Max((int) $param),
            'min_length' => new MinLength((int) $param),
            'max_length' => new MaxLength((int) $param),
            default => new CustomStringRule($name, $param),
        };
    }
}
