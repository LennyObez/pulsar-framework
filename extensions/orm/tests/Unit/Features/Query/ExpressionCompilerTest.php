<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Extension\Orm\Domain\LikePattern;
use Pulsar\Extension\Orm\Domain\RawExpression;
use Pulsar\Extension\Orm\Features\Query\Expression;
use Pulsar\Extension\Orm\Features\Query\ExpressionCompiler;
use Pulsar\Extension\Orm\Internal\Support\BindingCounter;
use Pulsar\Extension\Orm\Internal\Support\IdentifierQuoter;

final class ExpressionCompilerTest extends TestCase
{
    private ExpressionCompiler $compiler;

    protected function setUp(): void
    {
        $quoter = new IdentifierQuoter(Driver::SQLite);
        $bindings = new BindingCounter();
        $this->compiler = new ExpressionCompiler($quoter, $bindings);
    }

    #[Test]
    public function compareGeneratesEqualsExpression(): void
    {
        $expr = $this->compiler->compare('status', '=', 'active');

        self::assertInstanceOf(Expression::class, $expr);
        self::assertStringContainsString('=', $expr->sql);
        self::assertCount(1, $expr->bindings);
    }

    #[Test]
    public function compareGeneratesNotEqualsExpression(): void
    {
        $expr = $this->compiler->compare('name', '!=', 'deleted');

        self::assertStringContainsString('!=', $expr->sql);
    }

    #[Test]
    public function isNullGeneratesIsNullClause(): void
    {
        $expr = $this->compiler->isNull('deleted_at');

        self::assertStringContainsString('IS NULL', $expr->sql);
        self::assertEmpty($expr->bindings);
    }

    #[Test]
    public function isNullWithNotGeneratesIsNotNullClause(): void
    {
        $expr = $this->compiler->isNull('email', not: true);

        self::assertStringContainsString('IS NOT NULL', $expr->sql);
    }

    #[Test]
    public function inGeneratesInClause(): void
    {
        $expr = $this->compiler->in('status', ['active', 'pending', 'review']);

        self::assertStringContainsString('IN', $expr->sql);
        self::assertCount(3, $expr->bindings);
    }

    #[Test]
    public function inWithNotGeneratesNotInClause(): void
    {
        $expr = $this->compiler->in('role', ['admin'], not: true);

        self::assertStringContainsString('NOT IN', $expr->sql);
    }

    #[Test]
    public function betweenGeneratesBetweenClause(): void
    {
        $expr = $this->compiler->between('age', 18, 65);

        self::assertStringContainsString('BETWEEN', $expr->sql);
        self::assertCount(2, $expr->bindings);
    }

    #[Test]
    public function likeGeneratesLikeClause(): void
    {
        $pattern = LikePattern::contains('test');
        $expr = $this->compiler->like('name', $pattern);

        self::assertStringContainsString('LIKE', $expr->sql);
        self::assertCount(1, $expr->bindings);
    }

    #[Test]
    public function likeWithNotGeneratesNotLikeClause(): void
    {
        $pattern = LikePattern::startsWith('pre');
        $expr = $this->compiler->like('title', $pattern, not: true);

        self::assertStringContainsString('NOT LIKE', $expr->sql);
    }

    #[Test]
    public function rawReturnsExpressionFromRawExpression(): void
    {
        $raw = new RawExpression('custom_func(col) > :val', ['val' => 5]);
        $expr = $this->compiler->raw($raw);

        self::assertSame('custom_func(col) > :val', $expr->sql);
        self::assertSame(['val' => 5], $expr->bindings);
    }

    #[Test]
    public function andCombinesExpressionsWithAnd(): void
    {
        $e1 = new Expression('"a" = :p1', ['p1' => 1]);
        $e2 = new Expression('"b" = :p2', ['p2' => 2]);

        $combined = $this->compiler->and([$e1, $e2]);

        self::assertStringContainsString('AND', $combined->sql);
        self::assertCount(2, $combined->bindings);
    }

    #[Test]
    public function orCombinesExpressionsWithOr(): void
    {
        $e1 = new Expression('"x" = :p1', ['p1' => 'a']);
        $e2 = new Expression('"y" = :p2', ['p2' => 'b']);

        $combined = $this->compiler->or([$e1, $e2]);

        self::assertStringContainsString('OR', $combined->sql);
        self::assertCount(2, $combined->bindings);
    }

    #[Test]
    public function existsGeneratesExistsClause(): void
    {
        $expr = $this->compiler->exists('SELECT 1 FROM t WHERE t.id = :id', ['id' => 1]);

        self::assertStringContainsString('EXISTS', $expr->sql);
        self::assertSame(['id' => 1], $expr->bindings);
    }
}
