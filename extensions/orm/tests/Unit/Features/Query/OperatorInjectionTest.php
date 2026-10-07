<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Extension\Orm\Exception\QueryBuilderException;
use Pulsar\Extension\Orm\Features\Query\ExpressionCompiler;
use Pulsar\Extension\Orm\Features\Query\JoinOnBuilder;
use Pulsar\Extension\Orm\Internal\Support\BindingCounter;
use Pulsar\Extension\Orm\Internal\Support\IdentifierQuoter;

/**
 * Tests operator allowlist validation to prevent SQL injection via operator parameters.
 *
 * @covers \Pulsar\Extension\Orm\Features\Query\ExpressionCompiler::validateOperator
 * @covers \Pulsar\Extension\Orm\Features\Query\ExpressionCompiler::compare
 * @covers \Pulsar\Extension\Orm\Features\Query\JoinOnBuilder::on
 * @covers \Pulsar\Extension\Orm\Features\Query\JoinOnBuilder::where
 * @covers \Pulsar\Extension\Orm\Exception\QueryBuilderException::invalidOperator
 */
final class OperatorInjectionTest extends TestCase
{
    private ExpressionCompiler $compiler;

    protected function setUp(): void
    {
        $quoter = new IdentifierQuoter(Driver::SQLite);
        $bindings = new BindingCounter();
        $this->compiler = new ExpressionCompiler($quoter, $bindings);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validOperatorProvider(): iterable
    {
        yield 'equals' => ['='];
        yield 'not equals !=' => ['!='];
        yield 'not equals <>' => ['<>'];
        yield 'less than' => ['<'];
        yield 'greater than' => ['>'];
        yield 'less than or equal' => ['<='];
        yield 'greater than or equal' => ['>='];
        yield 'LIKE' => ['LIKE'];
        yield 'NOT LIKE' => ['NOT LIKE'];
        yield 'IN' => ['IN'];
        yield 'NOT IN' => ['NOT IN'];
        yield 'IS' => ['IS'];
        yield 'IS NOT' => ['IS NOT'];
        yield 'BETWEEN' => ['BETWEEN'];
        yield 'lowercase like' => ['like'];
        yield 'mixed case Like' => ['Like'];
        yield 'lowercase not like' => ['not like'];
        yield 'padded spaces' => [' = '];
        yield 'lowercase between' => ['between'];
    }

    #[Test]
    #[DataProvider('validOperatorProvider')]
    public function allowsValidOperators(string $operator): void
    {
        $expr = $this->compiler->compare('col', $operator, 'val');

        self::assertNotEmpty($expr->sql);
        self::assertCount(1, $expr->bindings);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidOperatorProvider(): iterable
    {
        yield 'SQL injection via OR' => ['= 1 OR 1=1 --'];
        yield 'SQL injection via semicolon' => ['; DROP TABLE users --'];
        yield 'SQL injection via UNION' => ['UNION SELECT'];
        yield 'SQL injection via subquery' => ['= (SELECT password FROM users LIMIT 1)'];
        yield 'random string' => ['INVALID'];
        yield 'empty string' => [''];
        yield 'comment injection' => ['= 1 /*'];
        yield 'backtick injection' => ['= `admin`'];
        yield 'double dash comment' => ['-- comment'];
        yield 'hex injection' => ['= 0x41'];
        yield 'newline injection' => ["=\n1"];
        yield 'HAVING keyword' => ['HAVING'];
        yield 'EXISTS keyword' => ['EXISTS'];
        yield 'AND keyword' => ['AND'];
        yield 'OR keyword' => ['OR'];
    }

    #[Test]
    #[DataProvider('invalidOperatorProvider')]
    public function rejectsInvalidOperatorsInCompare(string $operator): void
    {
        $this->expectException(QueryBuilderException::class);
        $this->expectExceptionMessageMatches('/Invalid SQL operator/');

        $this->compiler->compare('col', $operator, 'val');
    }

    #[Test]
    public function validOperatorIsNormalizedToUpperCase(): void
    {
        $expr = $this->compiler->compare('col', 'like', 'val');

        self::assertStringContainsString('LIKE', $expr->sql);
    }

    #[Test]
    public function validateOperatorStaticMethodReturnsNormalizedOperator(): void
    {
        self::assertSame('=', ExpressionCompiler::validateOperator('='));
        self::assertSame('LIKE', ExpressionCompiler::validateOperator('like'));
        self::assertSame('NOT LIKE', ExpressionCompiler::validateOperator('not like'));
        self::assertSame('>=', ExpressionCompiler::validateOperator(' >= '));
    }

    #[Test]
    public function validateOperatorStaticMethodRejectsInvalid(): void
    {
        $this->expectException(QueryBuilderException::class);

        ExpressionCompiler::validateOperator('; DROP TABLE users');
    }

    #[Test]
    public function invalidOperatorExceptionContainsTheOffendingOperator(): void
    {
        $malicious = '= 1 OR 1=1 --';

        try {
            $this->compiler->compare('col', $malicious, 'val');
            self::fail('Expected QueryBuilderException');
        } catch (QueryBuilderException $e) {
            self::assertStringContainsString($malicious, $e->getMessage());
            self::assertStringContainsString('Invalid SQL operator', $e->getMessage());
        }
    }

    #[Test]
    public function joinOnBuilderRejectsInvalidOperatorInOn(): void
    {
        $quoter = new IdentifierQuoter(Driver::SQLite);
        $builder = new JoinOnBuilder($quoter, new BindingCounter());

        $this->expectException(QueryBuilderException::class);
        $this->expectExceptionMessageMatches('/Invalid SQL operator/');

        $builder->on('t0.id', '; DROP TABLE users --', 't1.id');
    }

    #[Test]
    public function joinOnBuilderRejectsInvalidOperatorInWhere(): void
    {
        $quoter = new IdentifierQuoter(Driver::SQLite);
        $builder = new JoinOnBuilder($quoter, new BindingCounter());

        $this->expectException(QueryBuilderException::class);
        $this->expectExceptionMessageMatches('/Invalid SQL operator/');

        $builder->where('t0.status', 'UNION SELECT', 'active');
    }

    #[Test]
    public function joinOnBuilderAcceptsValidOperators(): void
    {
        $quoter = new IdentifierQuoter(Driver::SQLite);
        $builder = new JoinOnBuilder($quoter, new BindingCounter());

        $builder->on('t0.id', '=', 't1.foreign_id');
        $builder->where('t0.status', '!=', 'deleted');

        $compiled = $builder->compile();

        self::assertStringContainsString('=', $compiled['sql']);
        self::assertStringContainsString('!=', $compiled['sql']);
    }

    #[Test]
    public function joinOnBuilderNormalizesOperatorCase(): void
    {
        $quoter = new IdentifierQuoter(Driver::SQLite);
        $builder = new JoinOnBuilder($quoter, new BindingCounter());

        $builder->on('t0.type', 'like', 't1.pattern');

        $compiled = $builder->compile();

        self::assertStringContainsString('LIKE', $compiled['sql']);
    }
}
