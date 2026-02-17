<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Extension\Orm\Contracts\RowQueryBuilderInterface;
use Pulsar\Extension\Orm\Features\Query\SelectBuilder;

final class OrWhereTest extends TestCase
{
    private function createBuilder(): SelectBuilder
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);

        return new SelectBuilder($connection)->from('users');
    }

    #[Test]
    public function orWhereAppendsOrGroupToExistingWheres(): void
    {
        $builder = $this->createBuilder();

        $builder->where('status', 'active');
        $builder->orWhere(function (RowQueryBuilderInterface $qb): void {
            $qb->where('role', 'admin');
        });

        $compiled = $builder->toSql();

        self::assertStringContainsString('OR', $compiled['sql']);
        self::assertStringContainsString('status', $compiled['sql']);
        self::assertStringContainsString('role', $compiled['sql']);
    }

    #[Test]
    public function orWhereWithMultipleConditionsInCallback(): void
    {
        $builder = $this->createBuilder();

        $builder->where('status', 'active');
        $builder->orWhere(function (RowQueryBuilderInterface $qb): void {
            $qb->where('role', 'admin');
            $qb->where('verified', 1);
        });

        $compiled = $builder->toSql();

        // The OR group should combine the callback conditions with AND
        self::assertStringContainsString('OR', $compiled['sql']);
        self::assertStringContainsString('AND', $compiled['sql']);
    }

    #[Test]
    public function orWhereWithEmptyCallbackDoesNothing(): void
    {
        $builder = $this->createBuilder();

        $builder->where('status', 'active');
        $builder->orWhere(function (RowQueryBuilderInterface $qb): void {
            // Empty callback
        });

        $compiled = $builder->toSql();

        self::assertStringNotContainsString('OR', $compiled['sql']);
    }

    #[Test]
    public function orWhereWithoutPreviousWheres(): void
    {
        $builder = $this->createBuilder();

        $builder->orWhere(function (RowQueryBuilderInterface $qb): void {
            $qb->where('role', 'admin');
        });

        $compiled = $builder->toSql();

        // Should just add the condition without OR grouping
        self::assertStringContainsString('WHERE', $compiled['sql']);
        self::assertStringNotContainsString('OR', $compiled['sql']);
    }

    #[Test]
    public function orWhereBindingsAreMerged(): void
    {
        $builder = $this->createBuilder();

        $builder->where('status', 'active');
        $builder->orWhere(function (RowQueryBuilderInterface $qb): void {
            $qb->where('role', 'admin');
        });

        $compiled = $builder->toSql();
        $bindings = $compiled['bindings'];

        self::assertContains('active', $bindings);
        self::assertContains('admin', $bindings);
    }

    #[Test]
    public function multipleOrWhereCalls(): void
    {
        $builder = $this->createBuilder();

        $builder->where('status', 'active');
        $builder->orWhere(function (RowQueryBuilderInterface $qb): void {
            $qb->where('role', 'admin');
        });
        $builder->orWhere(function (RowQueryBuilderInterface $qb): void {
            $qb->where('role', 'superadmin');
        });

        $compiled = $builder->toSql();

        self::assertStringContainsString('OR', $compiled['sql']);
        $bindings = $compiled['bindings'];
        self::assertContains('active', $bindings);
        self::assertContains('admin', $bindings);
        self::assertContains('superadmin', $bindings);
    }

    #[Test]
    public function orWhereWithOperatorInCallback(): void
    {
        $builder = $this->createBuilder();

        $builder->where('age', 18);
        $builder->orWhere(function (RowQueryBuilderInterface $qb): void {
            $qb->whereOp('score', '>=', 90);
        });

        $compiled = $builder->toSql();

        self::assertStringContainsString('OR', $compiled['sql']);
        self::assertStringContainsString('>=', $compiled['sql']);
    }
}
