<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Contract;

use Pulsar\Api\Api;

use function array_values;

/**
 * Immutable collection of WHERE conditions.
 */
#[Api(since: '1.0.0')]
final readonly class WhereConditions
{
    /** @var list<WhereCondition> */
    public array $conditions;

    /**
     * @param array<WhereCondition> $conditions
     */
    public function __construct(array $conditions = [])
    {
        $this->conditions = array_values($conditions);
    }

    public function isEmpty(): bool
    {
        return $this->conditions === [];
    }
}
