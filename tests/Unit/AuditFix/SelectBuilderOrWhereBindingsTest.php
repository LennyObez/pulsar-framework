<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AuditFix;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Extension\Orm\Contracts\RowQueryBuilderInterface;
use Pulsar\Extension\Orm\Features\Query\SelectBuilder;

/**
 * Verifies that orWhere() preserves all bindings from both
 * the previous WHERE clauses and the OR group.
 */
#[CoversClass(SelectBuilder::class)]
final class SelectBuilderOrWhereBindingsTest extends TestCase
{
    private ConnectionInterface $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->connection->method('driver')->willReturn(Driver::MySQL);
    }

    #[Test]
    public function orWherePreservesPreviousBindings(): void
    {
        // Arrange: build a query with a WHERE and then an orWhere
        $builder = new SelectBuilder($this->connection);
        $compiled = $builder
            ->from('users')
            ->where('status', 'active')
            ->orWhere(static function (RowQueryBuilderInterface $q): void {
                $q->where('role', 'admin');
            })
            ->toSql();

        // Assert: both bindings must be present
        self::assertCount(2, $compiled['bindings']);
        self::assertContains('active', $compiled['bindings']);
        self::assertContains('admin', $compiled['bindings']);
        self::assertStringContainsString('OR', $compiled['sql']);
    }

    #[Test]
    public function orWhereWithMultipleConditionsInBothGroups(): void
    {
        $builder = new SelectBuilder($this->connection);
        $compiled = $builder
            ->from('orders')
            ->where('status', 'paid')
            ->where('currency', 'USD')
            ->orWhere(static function (RowQueryBuilderInterface $q): void {
                $q->where('priority', 'high');
                $q->where('region', 'EU');
            })
            ->toSql();

        // All four bindings must be present (check values regardless of keys)
        $bindingValues = array_values($compiled['bindings']);
        self::assertCount(4, $bindingValues);
        self::assertContains('paid', $bindingValues);
        self::assertContains('USD', $bindingValues);
        self::assertContains('high', $bindingValues);
        self::assertContains('EU', $bindingValues);
    }

    #[Test]
    public function orWhereWithEmptyCallbackIsNoOp(): void
    {
        $builder = new SelectBuilder($this->connection);
        $compiled = $builder
            ->from('users')
            ->where('status', 'active')
            ->orWhere(static function (RowQueryBuilderInterface $q): void {
                // Empty callback adds no conditions
            })
            ->toSql();

        self::assertCount(1, $compiled['bindings']);
        self::assertContains('active', $compiled['bindings']);
        self::assertStringNotContainsString('OR', $compiled['sql']);
    }

    #[Test]
    public function chainedOrWhereAccumulatesAllBindings(): void
    {
        $builder = new SelectBuilder($this->connection);
        $compiled = $builder
            ->from('users')
            ->where('a', 1)
            ->orWhere(static function (RowQueryBuilderInterface $q): void {
                $q->where('b', 2);
            })
            ->orWhere(static function (RowQueryBuilderInterface $q): void {
                $q->where('c', 3);
            })
            ->toSql();

        self::assertCount(3, $compiled['bindings']);
        self::assertContains(1, $compiled['bindings']);
        self::assertContains(2, $compiled['bindings']);
        self::assertContains(3, $compiled['bindings']);
    }
}
