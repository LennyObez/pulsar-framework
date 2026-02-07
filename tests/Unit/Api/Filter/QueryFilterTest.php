<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Filter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Filter\FilterExpression;
use Pulsar\Api\Filter\FilterOperator;
use Pulsar\Api\Filter\QueryFilter;

#[CoversClass(QueryFilter::class)]
final class QueryFilterTest extends TestCase
{
    private QueryFilter $queryFilter;

    protected function setUp(): void
    {
        $this->queryFilter = new QueryFilter();
    }

    #[Test]
    public function equalOperatorMapsCorrectly(): void
    {
        $expressions = [FilterExpression::create('name', FilterOperator::Equal, 'John')];

        $conditions = $this->queryFilter->apply($expressions);

        self::assertCount(1, $conditions);
        self::assertSame('name', $conditions[0]['column']);
        self::assertSame('=', $conditions[0]['operator']);
        self::assertSame('John', $conditions[0]['value']);
    }

    #[Test]
    public function notEqualOperatorMapsCorrectly(): void
    {
        $expressions = [FilterExpression::create('status', FilterOperator::NotEqual, 'deleted')];

        $conditions = $this->queryFilter->apply($expressions);

        self::assertSame('!=', $conditions[0]['operator']);
    }

    #[Test]
    public function greaterThanOperator(): void
    {
        $expressions = [FilterExpression::create('age', FilterOperator::GreaterThan, 18)];
        $conditions = $this->queryFilter->apply($expressions);

        self::assertSame('>', $conditions[0]['operator']);
        self::assertSame(18, $conditions[0]['value']);
    }

    #[Test]
    public function greaterThanOrEqualOperator(): void
    {
        $expressions = [FilterExpression::create('age', FilterOperator::GreaterThanOrEqual, 21)];
        $conditions = $this->queryFilter->apply($expressions);

        self::assertSame('>=', $conditions[0]['operator']);
    }

    #[Test]
    public function lessThanOperator(): void
    {
        $expressions = [FilterExpression::create('age', FilterOperator::LessThan, 65)];
        $conditions = $this->queryFilter->apply($expressions);

        self::assertSame('<', $conditions[0]['operator']);
    }

    #[Test]
    public function lessThanOrEqualOperator(): void
    {
        $expressions = [FilterExpression::create('price', FilterOperator::LessThanOrEqual, 99.99)];
        $conditions = $this->queryFilter->apply($expressions);

        self::assertSame('<=', $conditions[0]['operator']);
    }

    #[Test]
    public function inOperatorMapsCorrectly(): void
    {
        $expressions = [FilterExpression::create('role', FilterOperator::In, ['admin', 'editor'])];

        $conditions = $this->queryFilter->apply($expressions);

        self::assertSame('IN', $conditions[0]['operator']);
        self::assertSame(['admin', 'editor'], $conditions[0]['value']);
    }

    #[Test]
    public function containsOperatorProducesLikeWithWildcards(): void
    {
        $expressions = [FilterExpression::create('name', FilterOperator::Contains, 'john')];

        $conditions = $this->queryFilter->apply($expressions);

        self::assertSame('LIKE', $conditions[0]['operator']);
        self::assertSame('%john%', $conditions[0]['value']);
    }

    #[Test]
    public function startsWithOperatorProducesLikeWithTrailingWildcard(): void
    {
        $expressions = [FilterExpression::create('name', FilterOperator::StartsWith, 'jo')];

        $conditions = $this->queryFilter->apply($expressions);

        self::assertSame('LIKE', $conditions[0]['operator']);
        self::assertSame('jo%', $conditions[0]['value']);
    }

    #[Test]
    public function likeEscapesPercentWildcard(): void
    {
        $expressions = [FilterExpression::create('name', FilterOperator::Contains, '100%')];

        $conditions = $this->queryFilter->apply($expressions);

        // The % in "100%" is escaped to \% by escapeLike (backslash escaped first)
        self::assertSame('%100\%%', $conditions[0]['value']);
    }

    #[Test]
    public function likeEscapesUnderscoreWildcard(): void
    {
        $expressions = [FilterExpression::create('name', FilterOperator::Contains, 'a_b')];

        $conditions = $this->queryFilter->apply($expressions);

        // The _ in "a_b" is escaped to \_ by escapeLike (backslash escaped first)
        self::assertSame('%a\_b%', $conditions[0]['value']);
    }

    #[Test]
    public function likeEscapesBackslash(): void
    {
        $expressions = [FilterExpression::create('path', FilterOperator::Contains, 'C:\\Users')];

        $conditions = $this->queryFilter->apply($expressions);

        // Backslash should be escaped to \\\\ (PHP string: \\\\)
        // The input 'C:\Users' becomes 'C:\\Users' after LIKE escaping
        self::assertSame('%C:\\\\Users%', $conditions[0]['value']);
    }

    #[Test]
    public function multipleExpressionsProduceMultipleConditions(): void
    {
        $expressions = [
            FilterExpression::create('name', FilterOperator::Contains, 'john'),
            FilterExpression::create('age', FilterOperator::GreaterThanOrEqual, 18),
            FilterExpression::create('active', FilterOperator::Equal, true),
        ];

        $conditions = $this->queryFilter->apply($expressions);

        self::assertCount(3, $conditions);
        self::assertSame('name', $conditions[0]['column']);
        self::assertSame('age', $conditions[1]['column']);
        self::assertSame('active', $conditions[2]['column']);
    }

    #[Test]
    public function emptyExpressionsProduceEmptyConditions(): void
    {
        $conditions = $this->queryFilter->apply([]);

        self::assertSame([], $conditions);
    }

    #[Test]
    public function columnNamePreservedFromExpression(): void
    {
        $expressions = [FilterExpression::create('users.email', FilterOperator::Equal, 'test@example.com')];

        $conditions = $this->queryFilter->apply($expressions);

        self::assertSame('users.email', $conditions[0]['column']);
    }
}
