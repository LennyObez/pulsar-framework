<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Group;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\RuleInterface;

use function array_keys;

/**
 * A named group of validation rules for subset execution.
 *
 * Groups allow different rule sets for different contexts
 * (e.g. 'create' vs 'update' operations).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ValidationGroup
{
    /** @var array<string, list<RuleInterface>> */
    public array $rules;

    /**
     * @param array<string, list<RuleInterface>> $rules Field-to-rules map
     */
    public function __construct(
        public string $name,
        array $rules = [],
    ) {
        $this->rules = $rules;
    }

    /**
     * Create a new group with an additional field rule set.
     *
     * @param list<RuleInterface> $fieldRules
     */
    #[NoDiscard]
    public function withField(string $field, array $fieldRules): self
    {
        $rules = $this->rules;
        $rules[$field] = $fieldRules;

        return new self($this->name, $rules);
    }

    /**
     * Whether this group has rules for the given field.
     */
    public function hasField(string $field): bool
    {
        return isset($this->rules[$field]);
    }

    /**
     * Get the field names covered by this group.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        return array_keys($this->rules);
    }
}
