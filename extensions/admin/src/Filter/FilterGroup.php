<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Filter;

use Pulsar\Api\Api;

use function array_map;
use function count;

/**
 * A group of filter conditions combined with AND/OR logic.
 *
 * Supports nesting for complex filter expressions:
 *   (name = 'John' AND age > 18) OR (role = 'admin')
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class FilterGroup
{
    /**
     * @param FilterLogic $logic How conditions in this group are combined
     * @param list<FilterCondition> $conditions Filter conditions
     * @param list<FilterGroup> $groups Nested filter groups
     */
    public function __construct(
        public FilterLogic $logic = FilterLogic::And,
        public array $conditions = [],
        public array $groups = [],
    ) {}

    /**
     * Check if this group has any conditions or sub-groups.
     */
    public function isEmpty(): bool
    {
        return $this->conditions === [] && $this->groups === [];
    }

    /**
     * Count total conditions including nested groups.
     */
    public function totalConditions(): int
    {
        $count = count($this->conditions);

        foreach ($this->groups as $group) {
            $count += $group->totalConditions();
        }

        return $count;
    }

    /**
     * @param array{
     *     logic?: string,
     *     conditions?: list<array<string, mixed>>,
     *     groups?: list<array<string, mixed>>,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $logic = FilterLogic::tryFrom($data['logic'] ?? 'and') ?? FilterLogic::And;

        $conditions = [];
        foreach ($data['conditions'] ?? [] as $condData) {
            $conditions[] = FilterCondition::fromArray($condData);
        }

        $groups = [];
        foreach ($data['groups'] ?? [] as $groupData) {
            $groups[] = self::fromArray($groupData);
        }

        return new self(
            logic: $logic,
            conditions: $conditions,
            groups: $groups,
        );
    }

    /**
     * @return array{logic: string, conditions: list<array{field: string, operator: string, value: mixed}>, groups: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'logic' => $this->logic->value,
            'conditions' => array_map(
                static fn(FilterCondition $c): array => $c->toArray(),
                $this->conditions,
            ),
            'groups' => array_map(
                static fn(FilterGroup $g): array => $g->toArray(),
                $this->groups,
            ),
        ];
    }
}
