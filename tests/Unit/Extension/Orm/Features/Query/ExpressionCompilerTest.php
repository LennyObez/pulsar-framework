<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Features\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Extension\Orm\Domain\LikePattern;
use Pulsar\Extension\Orm\Domain\RawExpression;
use Pulsar\Extension\Orm\Features\Query\Expression;
use Pulsar\Extension\Orm\Features\Query\ExpressionCompiler;
use Pulsar\Extension\Orm\Internal\Support\BindingCounter;
use Pulsar\Extension\Orm\Internal\Support\IdentifierQuoter;

#[CoversClass(ExpressionCompiler::class)]
#[CoversClass(Expression::class)]
final class ExpressionCompilerTest extends TestCase
{
    private ExpressionCompiler $compiler;
    private BindingCounter $bindings;

    protected function setUp(): void
    {
        $quoter = new IdentifierQuoter(Driver::MySQL);
        $this->bindings = new BindingCounter();
        $this->compiler = new ExpressionCompiler($quoter, $this->bindings);
    }

    #[Test]
    public function compareGeneratesEquality(): void
    {
        $expr = $this->compiler->compare('name', '=', 'Alice');

        self::assertSame('`name` = :p0', $expr->sql);
        self::assertSame(['p0' => 'Alice'], $expr->bindings);
    }

    #[Test]
    public function compareGeneratesGreaterThan(): void
    {
        $expr = $this->compiler->compare('age', '>', 18);

        self::assertSame('`age` > :p0', $expr->sql);
        self::assertSame(['p0' => 18], $expr->bindings);
    }

    #[Test]
    public function isNullGeneratesIsNull(): void
    {
        $expr = $this->compiler->isNull('deleted_at');

        self::assertSame('`deleted_at` IS NULL', $expr->sql);
        self::assertSame([], $expr->bindings);
    }

    #[Test]
    public function isNullWithNotGeneratesIsNotNull(): void
    {
        $expr = $this->compiler->isNull('deleted_at', true);

        self::assertSame('`deleted_at` IS NOT NULL', $expr->sql);
    }

    #[Test]
    public function inGeneratesInClause(): void
    {
        $expr = $this->compiler->in('status', ['active', 'pending', 'review']);

        self::assertSame('`status` IN (:p0, :p1, :p2)', $expr->sql);
        self::assertSame(['p0' => 'active', 'p1' => 'pending', 'p2' => 'review'], $expr->bindings);
    }

    #[Test]
    public function notInGeneratesNotInClause(): void
    {
        $expr = $this->compiler->in('status', ['archived'], true);

        self::assertSame('`status` NOT IN (:p0)', $expr->sql);
        self::assertSame(['p0' => 'archived'], $expr->bindings);
    }

    #[Test]
    public function betweenGeneratesBetweenClause(): void
    {
        $expr = $this->compiler->between('age', 18, 65);

        self::assertSame('`age` BETWEEN :p0 AND :p1', $expr->sql);
        self::assertSame(['p0' => 18, 'p1' => 65], $expr->bindings);
    }

    #[Test]
    public function likeGeneratesLikeClause(): void
    {
        $pattern = LikePattern::contains('test');
        $expr = $this->compiler->like('name', $pattern);

        self::assertSame('`name` LIKE :p0', $expr->sql);
        self::assertSame(['p0' => '%test%'], $expr->bindings);
    }

    #[Test]
    public function notLikeGeneratesNotLikeClause(): void
    {
        $pattern = LikePattern::startsWith('admin');
        $expr = $this->compiler->like('username', $pattern, true);

        self::assertSame('`username` NOT LIKE :p0', $expr->sql);
        self::assertSame(['p0' => 'admin%'], $expr->bindings);
    }

    #[Test]
    public function andCombinesExpressions(): void
    {
        $a = Expression::of('`name` = :p0', ['p0' => 'Alice']);
        $b = Expression::of('`age` > :p1', ['p1' => 18]);

        $expr = $this->compiler->and([$a, $b]);

        self::assertSame('(`name` = :p0 AND `age` > :p1)', $expr->sql);
        self::assertSame(['p0' => 'Alice', 'p1' => 18], $expr->bindings);
    }

    #[Test]
    public function orCombinesExpressions(): void
    {
        $a = Expression::of('`status` = :p0', ['p0' => 'active']);
        $b = Expression::of('`status` = :p1', ['p1' => 'pending']);

        $expr = $this->compiler->or([$a, $b]);

        self::assertSame('(`status` = :p0 OR `status` = :p1)', $expr->sql);
        self::assertSame(['p0' => 'active', 'p1' => 'pending'], $expr->bindings);
    }

    #[Test]
    public function rawPassesThroughExpression(): void
    {
        $raw = RawExpression::of('COUNT(*) > :threshold', ['threshold' => 5]);
        $expr = $this->compiler->raw($raw);

        self::assertSame('COUNT(*) > :threshold', $expr->sql);
        self::assertSame(['threshold' => 5], $expr->bindings);
    }

    #[Test]
    public function existsWrapsSubquery(): void
    {
        $expr = $this->compiler->exists('SELECT 1 FROM `orders` WHERE `user_id` = :uid', ['uid' => 1]);

        self::assertSame('EXISTS (SELECT 1 FROM `orders` WHERE `user_id` = :uid)', $expr->sql);
        self::assertSame(['uid' => 1], $expr->bindings);
    }

    #[Test]
    public function bindingCounterIncrements(): void
    {
        $this->compiler->compare('a', '=', 1);
        $this->compiler->compare('b', '=', 2);
        $expr = $this->compiler->compare('c', '=', 3);

        self::assertSame('`c` = :p2', $expr->sql);
        self::assertSame(['p2' => 3], $expr->bindings);
    }

    #[Test]
    public function expressionOfFactory(): void
    {
        $expr = Expression::of('SELECT 1', ['key' => 'val']);

        self::assertSame('SELECT 1', $expr->sql);
        self::assertSame(['key' => 'val'], $expr->bindings);
    }
}
