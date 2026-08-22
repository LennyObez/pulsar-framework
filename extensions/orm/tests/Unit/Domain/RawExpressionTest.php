<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Domain\RawExpression;

final class RawExpressionTest extends TestCase
{
    #[Test]
    public function constructorStoresSqlAndBindings(): void
    {
        $expr = new RawExpression('COUNT(*) > :min', ['min' => 5]);

        self::assertSame('COUNT(*) > :min', $expr->sql);
        self::assertSame(['min' => 5], $expr->bindings);
    }

    #[Test]
    public function defaultBindingsIsEmpty(): void
    {
        $expr = new RawExpression('NOW()');

        self::assertSame('NOW()', $expr->sql);
        self::assertSame([], $expr->bindings);
    }

    #[Test]
    public function ofFactoryCreatesSameResult(): void
    {
        $expr = RawExpression::of('SUM(amount) > :limit', ['limit' => 100]);

        self::assertSame('SUM(amount) > :limit', $expr->sql);
        self::assertSame(['limit' => 100], $expr->bindings);
    }
}
