<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Extension\Orm\Features\Query\JoinOnBuilder;
use Pulsar\Extension\Orm\Features\Query\SelectBuilder;

use function array_keys;
use function array_unique;
use function array_values;
use function preg_match_all;
use function sort;

/**
 * orWhere() keeps the bindings that belong to clauses it does not touch.
 *
 * It swaps `$this->bindings` out for the duration of the callback so the group
 * can be compiled in isolation, then puts the two halves back together. When no
 * WHERE preceded the group it took a shortcut and adopted the group's map
 * wholesale — which is only equivalent if nothing else had contributed a
 * binding. A JOIN ON clause comparing a column to a VALUE contributes one, and
 * has no WHERE anywhere near it, so that binding was dropped: toSql() returned
 * SQL naming a parameter its own map no longer carried, and the driver rejected
 * the statement at execution.
 */
final class OrWhereJoinBindingsTest extends TestCase
{
    private function makeConnection(): ConnectionInterface
    {
        $stub = $this->createStub(ConnectionInterface::class);
        $stub->method('driver')->willReturn(Driver::SQLite);

        return $stub;
    }

    /**
     * @param array<string, mixed> $bindings
     */
    private function assertEveryPlaceholderIsBound(string $sql, array $bindings): void
    {
        preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $sql, $matches);

        /** @var list<string> $names */
        $names = $matches[1];

        $distinct = array_values(array_unique($names));
        sort($distinct);

        $bound = array_keys($bindings);
        sort($bound);

        self::assertSame($distinct, $bound, 'The SQL and its binding map name different parameters.');
    }

    #[Test]
    public function anOrWhereGroupDoesNotDiscardAJoinsBoundValue(): void
    {
        $builder = new SelectBuilder($this->makeConnection());
        $compiled = $builder
            ->from('orders')
            ->innerJoin('order_items', 'oi', static function (JoinOnBuilder $on): void {
                $on->on('t0.id', '=', 'oi.order_id');
                $on->where('oi.quantity', '>', 5);
            })
            // No WHERE precedes this group: that is the branch that used to
            // adopt the group's binding map and throw the rest away.
            ->orWhere(static function (SelectBuilder $q): void {
                $q->where('status', 'paid');
            })
            ->toSql();

        $this->assertEveryPlaceholderIsBound($compiled['sql'], $compiled['bindings']);

        self::assertContains(5, $compiled['bindings']);
        self::assertContains('paid', $compiled['bindings']);
        self::assertCount(2, $compiled['bindings']);
    }

    #[Test]
    public function aPrecedingWhereStillKeepsBothHalvesAndTheJoin(): void
    {
        $builder = new SelectBuilder($this->makeConnection());
        $compiled = $builder
            ->from('orders')
            ->innerJoin('order_items', 'oi', static function (JoinOnBuilder $on): void {
                $on->where('oi.quantity', '>', 5);
            })
            ->where('currency', 'EUR')
            ->orWhere(static function (SelectBuilder $q): void {
                $q->where('status', 'paid');
            })
            ->toSql();

        $this->assertEveryPlaceholderIsBound($compiled['sql'], $compiled['bindings']);

        self::assertContains(5, $compiled['bindings']);
        self::assertContains('EUR', $compiled['bindings']);
        self::assertContains('paid', $compiled['bindings']);
        self::assertCount(3, $compiled['bindings']);
    }

    #[Test]
    public function anEmptyOrWhereGroupLeavesTheQueryExactlyAsItWas(): void
    {
        $builder = new SelectBuilder($this->makeConnection());
        $compiled = $builder
            ->from('orders')
            ->innerJoin('order_items', 'oi', static function (JoinOnBuilder $on): void {
                $on->where('oi.quantity', '>', 5);
            })
            ->orWhere(static function (SelectBuilder $q): void {
                // Adds nothing.
            })
            ->toSql();

        $this->assertEveryPlaceholderIsBound($compiled['sql'], $compiled['bindings']);

        self::assertContains(5, $compiled['bindings']);
        self::assertCount(1, $compiled['bindings']);
    }
}
