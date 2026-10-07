<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Features\Query\Expression;

final class ExpressionTest extends TestCase
{
    #[Test]
    public function constructorStoresSqlAndBindings(): void
    {
        $expr = new Expression('t0.name = :p0', ['p0' => 'Alice']);

        self::assertSame('t0.name = :p0', $expr->sql);
        self::assertSame(['p0' => 'Alice'], $expr->bindings);
    }

    #[Test]
    public function defaultBindingsIsEmpty(): void
    {
        $expr = new Expression('1 = 1');

        self::assertSame([], $expr->bindings);
    }

    #[Test]
    public function ofFactoryMethod(): void
    {
        $expr = Expression::of('t0.status = :p0', ['p0' => 'active']);

        self::assertSame('t0.status = :p0', $expr->sql);
        self::assertSame(['p0' => 'active'], $expr->bindings);
    }
}
