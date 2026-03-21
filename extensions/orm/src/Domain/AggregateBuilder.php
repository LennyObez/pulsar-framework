<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Domain;

use Closure;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;

use function sprintf;

/**
 * Executes aggregate queries (COUNT, SUM, MIN, MAX, AVG) efficiently.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AggregateBuilder
{
    /**
     * @param Closure(string): string $quoteIdentifier
     * @param list<string> $compiledJoins
     * @param list<string> $compiledWheres
     * @param array<string, mixed> $bindings
     */
    public function __construct(
        private ConnectionInterface $connection,
        private Closure $quoteIdentifier,
        private string $compiledFrom,
        private array $compiledJoins,
        private array $compiledWheres,
        private array $bindings,
    ) {}

    #[NoDiscard]
    public function count(string $column = '*'): int
    {
        $value = $this->aggregate('COUNT', $column);

        return is_numeric($value) ? (int) $value : 0;
    }

    #[NoDiscard]
    public function sum(string $column): float
    {
        $value = $this->aggregate('SUM', $column);

        return is_numeric($value) ? (float) $value : 0.0;
    }

    #[NoDiscard]
    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    #[NoDiscard]
    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    #[NoDiscard]
    public function avg(string $column): ?float
    {
        $value = $this->aggregate('AVG', $column);

        return is_numeric($value) ? (float) $value : null;
    }

    private function aggregate(string $function, string $column): mixed
    {
        $colExpr = $column === '*' ? '*' : ($this->quoteIdentifier)($column);
        $selectExpr = sprintf('%s(%s) AS aggregate', $function, $colExpr);

        $sql = 'SELECT ' . $selectExpr . ' FROM ' . $this->compiledFrom;

        if ($this->compiledJoins !== []) {
            $sql .= ' ' . implode(' ', $this->compiledJoins);
        }

        if ($this->compiledWheres !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $this->compiledWheres);
        }

        $result = $this->connection->query($sql, $this->bindings);
        $row = $result->first();

        return $row?->get('aggregate');
    }
}
